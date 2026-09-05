<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\ImportJobDTO;
use Plathix\Http\RestAuditPayloadBuilders;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\ImportCheckpointStore;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;
use Plathix\Loader;

final class ImportManager
{
	/** @var ImportAdapterInterface[] */
	private array $adapters = [];

	/** @var \Closure(string, int): (int|\WP_Error) создание папки: (name, parent) → новый id|WP_Error */
	private \Closure $folder_creator;

	/** @var \Closure(int[], int): array<string,mixed> перенос вложений: (item_ids, folder_id) → результат move_items_bulk */
	private \Closure $items_mover;

	/** @var array{tree:FolderTreeService,assignment:FolderAssignmentService}|null лениво собранная связка для дефолтных операций */
	private ?array $default_services = null;

	/**
	 * @param ?\Closure $folder_creator (string $name, int $parent): array{id:int, created:bool}|\WP_Error —
	 *                                  операция создания папки. Дефолт (null) лениво собирает реальный
	 *                                  FolderTreeService и оборачивает create_detailed(): флаг created
	 *                                  различает «создал» и «переиспользовал существующую» — rollback
	 *                                  откатывает только созданные ([internal], [internal]). Инъекция —
	 *                                  для тестов без живой БД (эталон AttachmentReplaceService;
	 *                                  final-сервисы не мокаются объектом).
	 * @param ?\Closure $items_mover    (int[] $items, int $folder): array — операция переноса вложений;
	 *                                  дефолт оборачивает FolderAssignmentService::move_items_bulk().
	 */
	public function __construct(
		private readonly ?Loader $loader = null,
		?\Closure $folder_creator = null,
		?\Closure $items_mover = null
	) {
		$this->adapters[] = new Adapters\HappyFiles();
		$this->adapters[] = new Adapters\FileBird();
		$this->adapters[] = new Adapters\RealMediaLib();
		$this->adapters[] = new Adapters\WPMediaFolder();
		$this->adapters[] = new Adapters\WickedFolders();

		$this->folder_creator = $folder_creator ?? function (string $name, int $parent): array|\WP_Error {
			return $this->default_services()['tree']->create_detailed( $name, $parent, PLATHIX_TAXONOMY );
		};
		$this->items_mover = $items_mover ?? function (array $items, int $folder): array {
			return $this->default_services()['assignment']->move_items_bulk( $items, $folder, PLATHIX_TAXONOMY );
		};

		if ( $this->loader ) {
			$this->loader->add_action( 'plathix/import/job', $this, 'handle_job_import' );
		}
	}

	/**
	 * Лениво собирает реальную связку Folder-сервисов ОДИН раз (мемоизация), идентично прежней
	 * inline-сборке в import() (Cache::make → Repository → CountService → Tree/Assignment).
	 *
	 * @return array{tree:FolderTreeService,assignment:FolderAssignmentService}
	 */
	private function default_services(): array {
		if ( null === $this->default_services ) {
			$cache         = Cache::make();
			$repository    = new FolderRepository();
			$count_service = new FolderCountService( $repository, $cache );
			$this->default_services = [
				'tree'       => new FolderTreeService( $repository, $count_service ),
				'assignment' => new FolderAssignmentService( $repository, $count_service, $cache ),
			];
		}

		return $this->default_services;
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}

		add_action( 'plathix/import/job', [ $this, 'handle_job_import' ] );
	}

	/** @return array<string, bool> */
	public function available(): array {
		$result = [];
		foreach ( $this->adapters as $adapter ) {
			$result[ $adapter->key() ] = $adapter->is_available();
		}

		return $result;
	}

	/**
	 * Per-adapter факт "перенос из этого источника уже выполнен" ([internal]).
	 * Не изменяет available() — 4 существующих потребителя ([internal]) продолжают
	 * получать чистый bool-детект наличия данных; этот метод — отдельная, дополнительная
	 * персистентная пометка одноразовости импорта per источник.
	 *
	 * @return array<string,bool>
	 */
	public function imported(): array {
		$result = [];
		foreach ( $this->adapters as $adapter ) {
			$result[ $adapter->key() ] = (bool) get_option( self::imported_option_key( $adapter->key() ), false );
		}

		return $result;
	}

	private static function imported_option_key(string $adapter_key): string {
		return 'plathix_import_done_' . $adapter_key;
	}

	/**
	 * Есть ли незавершённый (не истёкший) checkpoint для adapter — сигнал для UI-баннера
	 * "продолжить/начать заново" ([internal]).
	 */
	public function has_pending_checkpoint(string $adapter_key): bool {
		$checkpoint_store = new ImportCheckpointStore();
		$checkpoint = $checkpoint_store->get( $adapter_key );

		return null !== $checkpoint && ! $checkpoint_store->is_expired( $checkpoint );
	}

	/**
	 * @return array{moved: int, errors: list<array{code: string, message: string}>}
	 */
	public function import(string $adapter_key): array {
		$adapter = $this->find_adapter( $adapter_key );

		if ( ! $adapter || ! $adapter->is_available() ) {
			return [ 'moved' => 0, 'errors' => [] ];
		}

		$lock_service = new JobLockService();
		$lock_name = 'import:' . $adapter_key;
		$lock = $lock_service->acquire_order( $lock_name );

		if ( 'none' === $lock['mode'] ) {
			// Конкурентный запуск того же adapter уже идёт — честный отказ, не exception
			// ([internal] чеклист: тот же контракт "занято", что и у reorder-лока).
			return [
				'moved'  => 0,
				'errors' => [ [ 'code' => 'import_locked', 'message' => __( 'Import is already running for this adapter.', 'plathix' ) ] ],
			];
		}

		try {
			return $this->import_locked( $adapter, $adapter_key );
		} finally {
			$lock_service->release_order( $lock_name, $lock );
		}
	}

	/**
	 * @return array{moved: int, errors: list<array{code: string, message: string}>}
	 */
	private function import_locked(ImportAdapterInterface $adapter, string $adapter_key): array {
		$checkpoint_store = new ImportCheckpointStore();
		$checkpoint = $checkpoint_store->get( $adapter_key );

		$map = [];
		$moved = 0;
		$created_ids = [];

		if ( null !== $checkpoint && ! $checkpoint_store->is_expired( $checkpoint ) ) {
			// Resume: карта из checkpoint используется как стартовое состояние, но каждый
			// parent_new > 0 проверяется на существование — ручная правка пользователем
			// (удаление папки) между обрывом и resume делает соответствующую ветку карты
			// протухшей, она исключается и пересоздаётся заново при следующем проходе BFS.
			// parent_new === 0 — корень дерева, всегда валиден, term_exists() не применим.
			foreach ( $checkpoint['map'] as $old_id => $new_id ) {
				if ( 0 === $new_id || term_exists( $new_id, PLATHIX_TAXONOMY ) ) {
					$map[ $old_id ] = $new_id;
				}
			}
			$moved = $checkpoint['moved'];
			// [internal]: учёт реально созданных терминов обязан переживать resume — иначе
			// после второго обрыва созданное первой попыткой выпадет из-под отката. Фильтр
			// term_exists симметричен карте выше (вручную удалённое юзером не воскрешаем в списке).
			foreach ( $checkpoint['created'] ?? [] as $created_id ) {
				if ( $created_id > 0 && term_exists( $created_id, PLATHIX_TAXONOMY ) ) {
					$created_ids[] = (int) $created_id;
				}
			}
		}

		$tree_data = $adapter->export_tree();
		$errors = [];

		$pending = $tree_data;
		$max_passes = count( $pending ) + 1;
		$pass = 0;

		while ( $pending !== [] && $pass++ < $max_passes ) {
			$still_pending = [];

			foreach ( $pending as $node ) {
				$old_id = absint( $node['id'] ?? 0 );
				$parent_old = absint( $node['parent'] ?? 0 );

				if ( $parent_old > 0 && ! isset( $map[ $parent_old ] ) ) {
					$still_pending[] = $node;
					continue;
				}

				if ( $old_id > 0 && isset( $map[ $old_id ] ) ) {
					// Узел уже создан в предыдущем прогоне (resume) — не пересоздавать.
					continue;
				}

				$parent_new = $map[ $parent_old ] ?? 0;
				$created = ( $this->folder_creator )( (string) ( $node['name'] ?? 'Imported' ), $parent_new );
				if ( is_wp_error( $created ) ) {
					// Причина сохраняется ([internal]): раньше узел молча пропадал без trace,
					// в отличие от соседнего StructureImporter, который хотя бы считал ошибки.
					$errors[] = [
						'code'    => (string) $created->get_error_code(),
						'message' => (string) $created->get_error_message(),
					];
					continue;
				}
				/** @var array{id: int, created: bool} $created Narrowed after is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */

				$new_id = (int) $created['id'];
				if ( $old_id > 0 ) {
					$map[ $old_id ] = $new_id;
				}
				// [internal]: в откатываемый список попадают только реально созданные термины;
				// переиспользованные существующие папки (created=false) rollback не трогает.
				if ( $created['created'] ) {
					$created_ids[] = $new_id;
				}

				$items = array_values( array_filter( array_map( 'absint', (array) ( $node['items'] ?? [] ) ) ) );
				if ( $items !== [] ) {
					$result = ( $this->items_mover )( $items, $new_id );
					$moved += (int) ( $result['moved'] ?? 0 );
				}
			}

			$pending = $still_pending;

			// Checkpoint после каждой волны: обрыв ПОСЛЕ волны не теряет прогресс этой волны.
			$checkpoint_store->save( $adapter_key, $map, $moved, $created_ids );
		}

		$checkpoint_store->delete( $adapter_key );

		return [ 'moved' => $moved, 'errors' => $errors ];
	}

	/**
	 * Откатывает частично созданное дерево по checkpoint (вызыватели: TTL cleanup-джоба,
	 * [internal], и AJAX «начать заново», [internal]). Удаляет ТОЛЬКО термины из
	 * checkpoint['created'] — реально вставленные импортом; переиспользованные существующие
	 * папки пользователя (create-or-reuse семантика create_detailed) в карте есть, но не
	 * откатываются ([internal]). Порядок — обратный порядку вставки (дети раньше родителей):
	 * wp_delete_term() на родителя с живыми детьми их переприкрепляет, а не удаляет.
	 *
	 * Взаимное исключение живёт здесь, у владельца операции, а не в вызывателях: оба лока
	 * в существующем ацикличном порядке import→structure (тот же, что import()→create();
	 * встречного пути structure→import в кодовой базе нет — проверено по всем
	 * acquire_order-захватам при паковке [internal]). Это закрывает гонки: удаление
	 * из-под бегущей import-джобы того же адаптера, double-rollback (restart+TTL), и
	 * term-сироту под удалённым родителем при параллельном юзерском create/move
	 * (класс [internal]).
	 *
	 * @return string 'done' — откат выполнен (или fail-safe: checkpoint без 'created' удалён
	 *                без сноса терминов — записи кода до [internal] не знают создателей,
	 *                ошибаемся в сторону НЕ удалять чужое); 'locked' — конкурентная операция
	 *                держит лок, ничего не сделано (checkpoint цел); 'noop' — checkpoint нет.
	 */
	public function rollback_partial(string $adapter_key): string {
		$checkpoint_store = new ImportCheckpointStore();

		// Быстрый выход без мутаций; авторитетное чтение — ниже, под локами.
		if ( null === $checkpoint_store->get( $adapter_key ) ) {
			return 'noop';
		}

		$lock_service = new JobLockService();
		$lock_name = 'import:' . $adapter_key;
		$import_lock = $lock_service->acquire_order( $lock_name );
		if ( 'none' === $import_lock['mode'] ) {
			return 'locked';
		}

		try {
			$tree = $this->default_services()['tree'];
			$structure_lock = $tree->acquire_structure_lock( PLATHIX_TAXONOMY );
			if ( 'none' === $structure_lock['mode'] ) {
				return 'locked';
			}

			try {
				// Авторитетная перечитка под локами (fast-path выше — TOCTOU): свежий экземпляр
				// stateless-store, иначе phpstan мемоизирует повторный вызов на том же объекте.
				$checkpoint = ( new ImportCheckpointStore() )->get( $adapter_key );
				if ( null === $checkpoint ) {
					// Конкурент успел завершить/откатить между быстрым чтением и захватом.
					return 'noop';
				}

				foreach ( array_reverse( $checkpoint['created'] ?? [] ) as $new_id ) {
					if ( $new_id > 0 ) {
						wp_delete_term( (int) $new_id, PLATHIX_TAXONOMY );
					}
				}

				$checkpoint_store->delete( $adapter_key );

				return 'done';
			} finally {
				$tree->release_structure_lock( PLATHIX_TAXONOMY, $structure_lock );
			}
		} finally {
			$lock_service->release_order( $lock_name, $import_lock );
		}
	}

	/**
	 * Транспорт-нейтральная точка старта импорта: собирает payload и ставит job в очередь.
	 * Единый источник, устраняющий дублирование сборки payload между AJAX и PublicApi
	 * ([internal]) — оба адаптера становятся тонкими обёртками над этим методом.
	 * Rate-limit pre-check остаётся в вызывающих транспортах (разная HTTP-семантика
	 * 429-кодов); adapter-availability check тоже остаётся снаружи (у каждого транспорта
	 * свой $available-loader).
	 *
	 * BOUNDDTO-002 ([internal]): возвращает {@see ImportJobDTO}, а не голый массив —
	 * оба транспорта читают свойства, поэтому опечатка в имени поля становится ошибкой
	 * анализа. Раньше форма жила в `@return array{...}`, который при чтении через `??`
	 * не проверяется (`treatPhpDocTypesAsCertain: false`, `phpstan.neon:20`) — это и
	 * пропустило баг #452.
	 */
	public function start_import(string $adapter, string $post_type, int $user_id): ImportJobDTO {
		$job_id = ( new JobDispatcher() )->dispatch(
			JobDispatcher::JOB_IMPORT,
			[
				'adapter'   => $adapter,
				'user_id'   => $user_id,
				'post_type' => $post_type,
			]
		);

		if ( $job_id > 0 ) {
			// [internal] (issues #619/#615): запись аудита перенесена сюда с удаляемого
			// REST-пути (JobsController::start_import_job). Здесь она транспорт-нейтральна:
			// через эту точку идут оба живых транспорта — AJAX и PublicApi\ImportExportApi,
			// — тогда как REST-путь UI никогда не использовал, и старт импорта из интерфейса
			// в журнал не попадал вовсе. objectId несёт РЕАЛЬНЫЙ job_id из dispatch, а не
			// ожидаемое значение ([internal]: третье вхождение того же рассинхрона).
			do_action(
				'plathix/audit/record',
				'import_job_queued',
				RestAuditPayloadBuilders::import_job( $adapter, $post_type, $job_id )
			);
		}

		return new ImportJobDTO(
			$job_id > 0 ? 'queued' : 'dispatch_failed',
			$job_id > 0 ? $job_id : 0,
			$adapter,
			$post_type
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function handle_job_import(array $args = []): void {
		$adapter = sanitize_key( (string) ( $args['adapter'] ?? '' ) );
		if ( '' === $adapter ) {
			return;
		}

		// [internal] ([internal], переоткрытие): раньше imported()-флаг ставился
		// безусловно сразу после import(), независимо от результата — если folder_creator/
		// items_mover тихо проваливались на каждом узле (WP_Error → continue, без исключения),
		// $moved оставался 0, но кнопка навсегда блокировалась как "уже перенесено", хотя
		// реально не перенеслось ничего (найдено фактом на живом проде клиента A: 7 папок
		// wpmf-category в БД, ни одной в Plathix, флаг уже стоял). Пустое исходное дерево —
		// легитимный no-op успех (нечего было переносить), не провал.
		$tree_adapter = $this->find_adapter( $adapter );
		$had_source_data = $tree_adapter && $tree_adapter->export_tree() !== [];
		// [internal]: SQL-fail адаптера (export_tree() вернул [] из-за реальной ошибки, не
		// потому что источник пуст) неотличим от "нет данных" на уровне $had_source_data —
		// читаем сигнал СРАЗУ после этого вызова, до второго export_tree() внутри import().
		$source_query_failed = $tree_adapter && $tree_adapter->had_query_failure();

		$result = $this->import( $adapter );
		$moved  = $result['moved'];

		if ( $result['errors'] !== [] ) {
			foreach ( $result['errors'] as $error ) {
				Logger::warning(
					'Import: folder creation failed',
					[ 'adapter' => $adapter, 'code' => $error['code'], 'message' => $error['message'] ]
				);
			}
		}

		// [internal]: SQL-fail ≠ "нет данных" — не персистим imported=true навсегда, если
		// единственная причина $had_source_data=false была ошибка запроса, а не пустой источник.
		if ( $moved > 0 || ( ! $had_source_data && ! $source_query_failed ) ) {
			update_option( self::imported_option_key( $adapter ), true, false );
		}

		$jobs = new JobDispatcher();
		$action_id = $jobs->get_action_id( JobDispatcher::JOB_IMPORT, $args );

		if ( $action_id > 0 ) {
			// [internal]: единый формат записи с run_guarded() —
			// JobDispatcher::store_result_for_action() сам фильтрует null-значения (ни одно
			// из полей ниже физически не может быть null при текущей сигнатуре, поэтому
			// поведение не меняется) и добавляет `_created_at` — TTL-метка для
			// CleanupJobRunner::purge_stale_job_results() ([internal]).
			$jobs->store_result_for_action(
				$action_id,
				[
					'adapter' => $adapter,
					'moved'   => $moved,
					'user_id' => (int) ( $args['user_id'] ?? 0 ),
					'blog_id' => get_current_blog_id(),
				]
			);
		}
	}

	private function find_adapter(string $adapter_key): ?ImportAdapterInterface {
		foreach ( $this->adapters as $candidate ) {
			if ( $candidate->key() === $adapter_key ) {
				return $candidate;
			}
		}

		return null;
	}
}
