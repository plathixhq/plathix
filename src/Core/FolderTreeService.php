<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Modules\Trash\FolderTrashService;

final class FolderTreeService
{
	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $count_service
	) {
	}

	public function create(string $name, int $parent, string $taxonomy): int|\WP_Error {
		$detailed = $this->create_detailed( $name, $parent, $taxonomy );

		return is_wp_error( $detailed ) ? $detailed : $detailed['id'];
	}

	/**
	 * Как create(), но дополнительно сообщает, была ли папка реально создана.
	 *
	 * Семантика create() — create-or-reuse: при совпадении имени и родителя возвращается id
	 * УЖЕ существующей папки без вставки. Факт «создал/переиспользовал» различим только внутри
	 * этого сервиса (ветки create_locked), поэтому владелец факта — он: rollback импорта
	 * ([internal], [internal]) обязан откатывать только реально созданные термины и не
	 * трогать переиспользованные пользовательские папки.
	 *
	 * @return array{id: int, created: bool}|\WP_Error
	 */
	public function create_detailed(string $name, int $parent, string $taxonomy): array|\WP_Error {
		$normalized = $this->normalize_name( $name );
		if ( $error = $this->validate_name( $normalized ) ) {
			return $error;
		}

		if ( $error = $this->validate_taxonomy( $taxonomy ) ) {
			return $error;
		}

		if ( $this->repository->is_uncategorized_folder( $parent, $taxonomy ) ) {
			return new \WP_Error( 'invalid_parent', __( 'Cannot create subfolders inside Uncategorized.', 'plathix' ) );
		}

		$depth_limit = (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH );
		if ( $depth_limit > 0 && $this->get_depth( $parent, $taxonomy ) >= $depth_limit ) {
			return new \WP_Error( 'depth_limit', __( 'Maximum folder nesting depth reached.', 'plathix' ) );
		}

		// structure-замок ([internal], lock-ordering решён в
		// [internal].md TLS-101): берётся ПОСЛЕ дешёвых input-валидаций выше (имя/таксономия/
		// uncategorized/depth не читают конкурентное состояние дерева), но ДО term_exists-проверки
		// и insert() — та же зона, где delete_recursive_body() читает список детей родителя без
		// лока извне. Без этого замка новый ребёнок мог вставиться после того, как параллельный
		// permanent-delete родителя уже прочитал список детей → term-сирота под удалённым
		// родителем. Insert-замок (внутри repository->insert(), per-parent, дедуп конкурентного
		// insert того же имени) берётся снаружи ЭТОГО замка — порядок не встречный, cм.
		// [internal].md.
		$lock_name    = $this->structure_lock_name( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquire_order( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		try {
			return $this->create_locked( $normalized, $parent, $taxonomy );
		} finally {
			$lock_service->release_order( $lock_name, $lock );
		}
	}

	/** @return array{id: int, created: bool}|\WP_Error */
	private function create_locked(string $normalized, int $parent, string $taxonomy): array|\WP_Error {
		// Trashed-guard ([internal]): под тем же локом, что term_exists/insert ниже —
		// проверяет родителя И всю цепочку предков (has_trashed_ancestor).
		if ( $parent > 0 && $this->has_trashed_ancestor( $parent, $taxonomy ) ) {
			return new \WP_Error( 'parent_trashed', __( 'Cannot create a folder inside a folder that is in Trash.', 'plathix' ), [ 'status' => 409 ] );
		}

		$exists = term_exists( $normalized, $taxonomy, $parent );
		if ( $exists ) {
			$existing_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
			if ( $existing_id > 0 ) {
				if ( ! $this->repository->get_meta( $existing_id, '_plathix_folder_trashed' ) ) {
					return [ 'id' => $existing_id, 'created' => false ];
				}
				// [internal]: сохранить оригинальное имя ДО перезаписи ниже — иначе
				// FolderRestoreService::restore() не сможет вернуть его при восстановлении из
				// корзины. Write-once (не перезаписываем, если уже сохранено при предыдущем
				// коллизионном hit на этот же term) — симметрично идемпотентности
				// FolderTrashService::mark_trashed() (WP Senior Dev skeptic pass).
				if ( ! $this->repository->get_meta( $existing_id, FolderTrashService::META_ORIGINAL_NAME ) ) {
					$existing_term = $this->repository->get_by_id( $existing_id, $taxonomy );
					if ( $existing_term instanceof \WP_Term ) {
						$this->repository->set_meta( $existing_id, FolderTrashService::META_ORIGINAL_NAME, $existing_term->name );
					}
				}
				// Soft-trashed term blocks wp_insert_term by both name and slug; free both so a fresh
				// term can be created. Name stays hidden (trashed), slug uniqueness is guaranteed by id.
				$upd = wp_update_term( $existing_id, $taxonomy, [
					'name' => (string) $existing_id . '__trashed',
					'slug' => (string) $existing_id . '__trashed',
				] );
				// [internal]: diagnostic log only under WP_DEBUG — no noise in production logs.
				if ( is_wp_error( $upd ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'plathix: slug-rename failed for term ' . $existing_id . ': ' . $upd->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only under WP_DEBUG
				}
			}
		}

		$inserted = $this->repository->insert( sanitize_text_field( $normalized ), $parent, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		/** @var int $inserted Narrowed after is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */
		$this->repository->set_meta( (int) $inserted, PLATHIX_TERM_POSITION, $this->next_position( (int) $inserted, $parent, $taxonomy ) );
		$this->count_service->invalidate( $taxonomy );
		do_action( 'plathix/folder/created', (int) $inserted, $taxonomy );

		return [ 'id' => (int) $inserted, 'created' => true ];
	}

	public function rename(int $id, string $name, string $taxonomy): bool|\WP_Error {
		if ( $this->repository->is_uncategorized_folder( $id, $taxonomy ) ) {
			return new \WP_Error( 'protected_folder', __( 'Uncategorized cannot be renamed.', 'plathix' ) );
		}

		$normalized = $this->normalize_name( $name );
		if ( $error = $this->validate_name( $normalized ) ) {
			return $error;
		}

		$result = $this->repository->update( $id, [ 'name' => sanitize_text_field( $normalized ) ], $taxonomy );
		if ( ! is_wp_error( $result ) ) {
			$this->count_service->invalidate( $taxonomy );
			do_action( 'plathix/folder/updated', $id, $taxonomy );

			return $result;
		}

		// [internal] (симметрия [internal]/move): в окне гонки с параллельным delete_recursive
		// переименовываемый term мог исчезнуть между чтением и update — wp_update_term вернёт
		// сырой invalid_term/invalid_taxonomy. Переводим в осмысленный folder_gone/409 «папка
		// удалена, обновите дерево», идентично move() (стр.116-122). Данные не портятся — это
		// лишь читаемость кода ошибки для REST-клиента. Rename вне structure-лока (в отличие от
		// move), но контракт ошибки — тот же. Остальные коды пробрасываем как есть.
		if ( in_array( $result->get_error_code(), [ 'invalid_term', 'invalid_taxonomy' ], true ) ) {
			return new \WP_Error(
				'folder_gone',
				__( 'This folder was removed. Please refresh the folder tree and try again.', 'plathix' ),
				[ 'status' => 409 ]
			);
		}

		return $result;
	}

	public function move(int $id, int $parent, string $taxonomy): bool|\WP_Error {
		if ( $error = $this->validate_self_move( $id, $parent ) ) {
			return $error;
		}

		if ( $this->is_descendant_of( $parent, $id, $taxonomy ) ) {
			return new \WP_Error( 'cycle_detected', __( 'Cannot move a folder into one of its own subfolders.', 'plathix' ), [ 'status' => 409 ] );
		}

		$depth_limit = (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH );
		if ( $depth_limit > 0 && $this->get_depth( $parent, $taxonomy ) >= $depth_limit ) {
			return new \WP_Error( 'depth_limit', __( 'Maximum folder nesting depth reached.', 'plathix' ), [ 'status' => 409 ] );
		}

		// Per-taxonomy structure-лок (G2, [internal]): move и delete_recursive не должны
		// пересекаться, иначе дети переезжают к уже удалённому родителю. Раньше здесь была
		// МЁРТВАЯ проверка plathix_structure_locked_ (option читался, но никем не писался).
		$lock_name    = $this->structure_lock_name( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquire_order( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		// [internal] MSC-103: read the OLD parent before update() overwrites it — needed
		// to decrement its recursive chain below. term is guaranteed to exist here (guards
		// above already validated $id/$parent); a null read would mean a race with a
		// concurrent delete, same [internal] window the invalid_term handling below covers.
		$old_term   = $this->repository->get_by_id( $id, $taxonomy );
		$old_parent = $old_term instanceof \WP_Term ? (int) $old_term->parent : 0;

		try {
			// Trashed-guard ([internal]): проверка ПОД тем же локом, что и сама мутация —
			// иначе параллельный trash() целевого родителя мог пройти между этой проверкой и
			// update() снаружи лока. Проверяет родителя И всю цепочку предков (has_trashed_ancestor).
			if ( $parent > 0 && $this->has_trashed_ancestor( $parent, $taxonomy ) ) {
				return new \WP_Error( 'parent_trashed', __( 'Cannot move a folder into a folder that is in Trash.', 'plathix' ), [ 'status' => 409 ] );
			}

			$result = $this->repository->update( $id, [ 'parent' => $parent ], $taxonomy );
		} finally {
			$lock_service->release_order( $lock_name, $lock );
		}

		if ( ! is_wp_error( $result ) ) {
			// [internal] MSC-103: this folder's own recursive count (its files + its
			// subtree) leaves the old parent's chain and enters the new one — same
			// direct-own-count decrement/increment pattern as FolderTrashService/
			// FolderRestoreService, but using get_recursive_count() here (not get_count()):
			// unlike a single-node trash/restore, a MOVED folder can carry its whole
			// subtree with it, so the full recursive total must transfer, not just its
			// own direct files.
			$moved_count = $this->count_service->get_recursive_count( $id, $taxonomy );
			if ( $moved_count > 0 ) {
				if ( $old_parent > 0 ) {
					$this->count_service->increment_recursive_chain( $old_parent, $taxonomy, -$moved_count );
				}
				if ( $parent > 0 ) {
					$this->count_service->increment_recursive_chain( $parent, $taxonomy, $moved_count );
				}
			}

			$this->count_service->invalidate( $taxonomy );
			do_action( 'plathix/folder/updated', $id, $taxonomy );

			return $result;
		}

		// [internal]: в окне гонки с параллельным delete_recursive родитель/узел мог исчезнуть
		// между взятием лока и update — wp_update_term вернёт сырой invalid_term/invalid_taxonomy.
		// Переводим в осмысленный 409 «папка уже удалена, обновите дерево», как остальные ветки
		// move (invalid_parent/cycle_detected/... все несут ['status'=>409]). Данные не портятся —
		// это лишь читаемость кода ошибки для REST-клиента. Остальные коды пробрасываем как есть.
		if ( in_array( $result->get_error_code(), [ 'invalid_term', 'invalid_taxonomy' ], true ) ) {
			return new \WP_Error(
				'folder_gone',
				__( 'This folder was removed. Please refresh the folder tree and try again.', 'plathix' ),
				[ 'status' => 409 ]
			);
		}

		return $result;
	}

	/**
	 * Per-taxonomy structure-lock name (blog + taxonomy). Сериализует move/delete_recursive/
	 * set_order ([internal], [internal] — set_order раньше строил per-branch order-лок
	 * по parent_id, прочитанному ДО захвата, что оставляло окно устаревания при
	 * конкурентном move того же термина; structure-лок не зависит от parent_id, поэтому
	 * такого окна нет). Отличается от per-branch order-лока
	 * (JobLockService::order_lock_name()), который остаётся за normalize_order()/
	 * ReorderJobRunner (их parent_id приходит параметром, а не re-read термина, поэтому
	 * они не подвержены тому же race) — structure- и order-лок по-прежнему НЕ
	 * пересекаются, ни один путь не берёт оба вложенно (граф локов ацикличен).
	 */
	private function structure_lock_name(string $taxonomy): string {
		return 'plathix_mv_' . get_current_blog_id() . '_' . md5( $taxonomy );
	}

	/**
	 * Публичный вход в тот же structure-замок, что используют move()/delete_recursive_permanent()
	 * ([internal]). Позволяет модулю Trash сериализовать
	 * trash()/restore() с остальным жизненным циклом дерева без дублирования формулы имени
	 * замка (structure_lock_name остаётся private — единственный владелец имени) и без
	 * прямой зависимости Trash на JobLockService/JobLockService-детали.
	 *
	 * Вызывающий обязан взять замок один раз на верхней границе своей операции и снять его в
	 * finally; вложенная рекурсия НЕ должна повторно вызывать этот метод (тот же принцип, что
	 * delete_recursive_permanent → delete_recursive_body).
	 *
	 * @return array{mode: string, opt_key: string|null}
	 */
	public function acquire_structure_lock(string $taxonomy): array {
		return ( new \Plathix\Infrastructure\JobLockService() )->acquire_order( $this->structure_lock_name( $taxonomy ) );
	}

	/**
	 * @param array{mode: string, opt_key: string|null} $lock_result Значение, возвращённое acquire_structure_lock().
	 */
	public function release_structure_lock(string $taxonomy, array $lock_result): void {
		( new \Plathix\Infrastructure\JobLockService() )->release_order( $this->structure_lock_name( $taxonomy ), $lock_result );
	}

	public function get_depth(int $folder_id, string $taxonomy): int {
		$depth = 0;
		$current = $folder_id;

		while ( $current > 0 ) {
			$term = $this->repository->get_by_id( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
			++$depth;
		}

		return $depth;
	}

	/**
	 * Удаление папки = мягкий трэш в корзину ([internal]), НЕ безвозвратный wp_delete_term.
	 *
	 * Папка помечается мета-флагом и может быть восстановлена ([internal]); физический снос
	 * наступает только при окончательной очистке / retention (delete_recursive_permanent,
	 * [internal]). [internal] (возврат к исходному дизайну [internal] после
	 * временной смены дефолта в [internal]): $on_children управляет каскадом — 'delete' (дефолт)
	 * трэшит всё поддерево целиком, как в файловой системе; 'reattach' — явная альтернатива,
	 * трэшит только саму папку и переподвешивает прямых детей на её родителя. Сам runner
	 * (FolderTrashRunner/FolderTrashService) делает работу — этот метод только резолвит и
	 * прокидывает параметр через слот.
	 *
	 * Логика мягкого удаления — собственность модуля Trash; резолвится через слот
	 * plathix/folder/trash_runner (дефолт — платформенный мост FolderTrashRunner). Так платформа
	 * не держит прямую связь с Modules\Trash (симметрия plathix/media/trash_runner, [internal]).
	 *
	 * @param string $on_children 'delete' (дефолт) — всё поддерево трэшится вместе с папкой;
	 *                            'reattach' — дети переподвешиваются на родителя удаляемой папки.
	 */
	public function delete_recursive(int $id, string $taxonomy, string $on_children = 'delete'): bool {
		$runner = apply_filters( 'plathix/folder/trash_runner', FolderTrashRunner::trash(...) );

		$trashed = (bool) $runner( $id, $taxonomy, $on_children );
		if ( $trashed ) {
			$this->count_service->invalidate( $taxonomy );
			do_action( 'plathix/folder/trashed', $id, $taxonomy );
		}

		return $trashed;
	}

	/**
	 * Безвозвратное рекурсивное удаление папки (прежнее поведение delete_recursive до [internal]).
	 *
	 * Используется окончательной очисткой корзины / retention ([internal]) и DataWiper. Берёт
	 * per-taxonomy structure-лок ОДИН раз (G2, [internal]), затем делегирует в рекурсивное тело
	 * без лока. Так вложенная рекурсия ('delete') не пытается захватить тот же лок повторно
	 * (option-fallback нереентрантен → был бы self-deadlock). Тот же лок, что move().
	 */
	public function delete_recursive_permanent(int $id, string $taxonomy, string $on_children = 'reattach'): bool {
		$lock_name    = $this->structure_lock_name( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquire_order( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return false;
		}

		try {
			return $this->delete_recursive_body( $id, $taxonomy, $on_children );
		} finally {
			$lock_service->release_order( $lock_name, $lock );
		}
	}

	/**
	 * Делегирует в delete_recursive_body() БЕЗ захвата лока — вызывающий обязан уже держать
	 * structure-lock той же таксономии (через acquire_structure_lock()). Симметрично тому,
	 * как сама delete_recursive_permanent() избегает self-deadlock при рекурсии в
	 * 'delete'-режиме ([internal], [internal]). Не звать без предварительного
	 * acquire_structure_lock() — GET_LOCK не реентрантен, повторный acquire_order() с тем же
	 * именем лока внутри уже открытого лока приведёт к self-deadlock.
	 */
	public function delete_recursive_under_lock(int $id, string $taxonomy, string $on_children = 'reattach'): bool {
		return $this->delete_recursive_body( $id, $taxonomy, $on_children );
	}

	/**
	 * Рекурсивное тело delete_recursive БЕЗ захвата лока — лок держит публичная обёртка.
	 * Рекурсит только в режиме 'delete'.
	 */
	private function delete_recursive_body(int $id, string $taxonomy, string $on_children): bool {
		if ( $id <= 0 || $this->repository->is_uncategorized_folder( $id, $taxonomy ) ) {
			return false;
		}

		// [internal] MSC-103: this folder's own count must leave its parent's chain,
		// UNLESS the folder was already soft-trashed (FolderTrashService::mark_trashed()
		// already decremented it when it entered Trash — the normal retention-cleanup
		// path calls THIS method on an already-trashed folder). Decrementing again here
		// unconditionally would double-subtract the same files. A live (never-trashed)
		// folder reaching this method directly (DataWiper, or a future direct-delete
		// caller) has NOT been decremented yet, so it must be here. Read parent + trashed
		// status + own count BEFORE repository->delete() removes the term — wp_delete_term()
		// clears term_relationships as part of the delete, so get_count() after delete()
		// would read back 0 regardless of what was actually there.
		$term_before_delete    = $this->repository->get_by_id( $id, $taxonomy );
		$parent_before_delete  = $term_before_delete instanceof \WP_Term ? (int) $term_before_delete->parent : 0;
		$was_already_trashed   = (string) $this->repository->get_meta( $id, FolderTrashService::META_TRASHED ) === '1';
		$own_count_before_delete = $was_already_trashed ? 0 : $this->count_service->get_count( $id, $taxonomy );

		$children = $this->repository->get_children_ids( $id, $taxonomy );

		if ( $on_children === 'delete' ) {
			foreach ( $children as $child_id ) {
				$this->delete_recursive_body( $child_id, $taxonomy, 'delete' );
			}
		} else {
			$term = $this->repository->get_by_id( $id, $taxonomy );
			$new_parent = $term instanceof \WP_Term ? (int) $term->parent : 0;
			$reparented = $this->reparent_children( $id, $new_parent, $taxonomy );
			if ( $reparented instanceof \WP_Error ) {
				return false;
			}
		}

		// [internal]: wp_delete_term() внутри repository->delete() чистит
		// term_relationships С per-file событиями — core идёт циклом по объектам терма и
		// зовёт wp_set_object_terms(object, array_diff(...)) для каждого (факт WP core,
		// вопреки прежнему докблоку выше). Без подавления событийный подписчик
		// FolderCountLifecycle задвоил бы явный агрегат ниже; suppress() + константный
		// агрегат — ровно perf-условие пакета (O(1) вместо O(files) на удаление папки).
		$deleted = FolderCountLifecycle::suppress(
			fn (): bool => $this->repository->delete( $id, $taxonomy )
		);
		if ( $deleted ) {
			if ( $own_count_before_delete > 0 && $parent_before_delete > 0 ) {
				$this->count_service->increment_recursive_chain( $parent_before_delete, $taxonomy, -$own_count_before_delete );
			}

			$this->count_service->invalidate( $taxonomy );
			do_action( 'plathix/folder/deleted', $id, $taxonomy );
		}

		return $deleted;
	}

	public function reparent_children(int $old_parent, int $new_parent, string $taxonomy): ?\WP_Error {
		if ( $old_parent === $new_parent ) {
			return null;
		}

		$result = $this->repository->bulk_update_parent( $old_parent, $new_parent, $taxonomy );
		if ( is_wp_error( $result ) ) {
			/** @var \WP_Error $result Narrowed inside is_wp_error() guard (see [internal] #6). */
			return $result;
		}

		clean_term_cache( $this->repository->get_children_ids( $new_parent, $taxonomy ), $taxonomy );
		delete_option( "{$taxonomy}_children" );
		$this->count_service->invalidate( $taxonomy );

		return null;
	}

	public function set_order(int $id, int $position, string $taxonomy): ?\WP_Error {
		// Structure-лок ([internal]): та же формула, что берёт move(), вместо
		// per-branch order-лока — раньше order-лок строился по parent_id, прочитанному
		// ДО захвата, и конкурентный move() того же термина мог сменить parent между
		// чтением и локом, оставляя запись позиции сериализованной относительно уже
		// неактуальной ветки ([internal]). Structure-лок не зависит от parent_id вовсе,
		// поэтому такого окна устаревания не существует по построению.
		$lock = $this->acquire_structure_lock( $taxonomy );

		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		try {
			$this->repository->set_meta( $id, PLATHIX_TERM_POSITION, $position );
		} finally {
			$this->release_structure_lock( $taxonomy, $lock );
		}

		$this->count_service->invalidate( $taxonomy );

		return null;
	}



	public function normalize_order(string $taxonomy, int $parent_id = 0): void {
		// Per-branch order-лок (не structure-лок — set_order с [internal] больше не
		// его сосед, см. structure_lock_name()). Раньше normalize писал ОТДЕЛЬНЫЙ ключ
		// plathix_structure_locked_ через update_option (маркер, не мьютекс), из-за чего
		// параллельный set_order того же parent мог конфликтовать по PLATHIX_TERM_POSITION
		// (G1, [internal]). При занятом локе normalize тихо выходит: пересчёт идемпотентен,
		// повтор безопасен, это фоновая нормализация без 409-контракта.
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock_name    = $lock_service->order_lock_name( $taxonomy, $parent_id );
		$lock         = $lock_service->acquire_order( $lock_name );

		if ( 'none' === $lock['mode'] ) {
			return;
		}

		try {
			$children = $this->repository->get_children_ids( $parent_id, $taxonomy );
			$position = 1000;
			foreach ( array_chunk( $children, 100 ) as $chunk ) {
				foreach ( $chunk as $child_id ) {
					$this->repository->set_meta( $child_id, PLATHIX_TERM_POSITION, $position );
					$position += 1000;
				}
			}
		} finally {
			$lock_service->release_order( $lock_name, $lock );
			$this->count_service->invalidate( $taxonomy );
		}
	}

	// -------------------------------------------------------------------------
	// Input invariants ([internal]): чистые входные проверки, вынесены из mutation-методов
	// как явные guard'ы. НЕ содержат побочных эффектов и не читают дерево (tree-walks —
	// depth/cycle/uncategorized — остаются inline, им нужен repository). Порядок вызова и коды
	// ошибок сохранены 1:1; статус навешивает call-site (кроме self-move, где код исторически 409).
	// -------------------------------------------------------------------------

	/** Нормализует имя папки: схлопывает пробелы, обрезает до 200 символов. Единый источник для create/rename. */
	private function normalize_name(string $name): string {
		return FolderName::normalize( $name );
	}

	/**
	 * Валидирует уже нормализованное имя папки ([internal]: общее правило с путём импорта
	 * пресетов — Modules\Preset\PresetValidator — через Core\FolderName). Не ужесточаем
	 * символьный лимит сверх текущей обрезки в normalize_name(): $mbLimit не передаётся,
	 * сохраняет наблюдаемый REST-контракт (narrowest safe interpretation при паковке).
	 */
	private function validate_name(string $normalized): ?\WP_Error {
		$errors = FolderName::validate( $normalized );

		if ( in_array( FolderName::ERROR_EMPTY, $errors, true ) ) {
			return new \WP_Error( 'empty_name', __( 'Folder name cannot be empty.', 'plathix' ) );
		}

		if ( in_array( FolderName::ERROR_LINE_BREAK, $errors, true ) || in_array( FolderName::ERROR_DANGEROUS_CHARS, $errors, true ) ) {
			return new \WP_Error( 'invalid_name_chars', __( 'Folder name contains control or bidirectional characters.', 'plathix' ) );
		}

		if ( in_array( FolderName::ERROR_TOO_LONG_BYTES, $errors, true ) ) {
			return new \WP_Error( 'name_too_long', __( 'Folder name is too long.', 'plathix' ) );
		}

		return null;
	}

	/** Таксономия должна существовать. */
	private function validate_taxonomy(string $taxonomy): ?\WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'plathix' ) );
		}

		return null;
	}

	/** Папку нельзя переместить в саму себя / невалидный id. Код invalid_parent несёт status 409. */
	private function validate_self_move(int $id, int $parent): ?\WP_Error {
		if ( $id <= 0 || $id === $parent ) {
			return new \WP_Error( 'invalid_parent', __( 'A folder cannot be moved to itself.', 'plathix' ), [ 'status' => 409 ] );
		}

		return null;
	}

	private function is_descendant_of(int $candidate_parent, int $folder_id, string $taxonomy): bool {
		// [internal]: WP не гарантирует ацикличность parent-цепочки термов на уровне API —
		// сторонний плагин/прямой wp_update_term()/SQL могут создать цикл A→B→A в обход
		// move()'s собственной cycle-проверки (эта проверка защищает только НОВУЮ операцию,
		// не существующие данные). $visited останавливает обход при повторном term_id вместо
		// бесконечного цикла; false здесь означает "искомое значение не найдено ДО того, как
		// цепочка начала повторяться" — тот же fail-open, что уже возвращает конец метода при
		// отсутствующем терме.
		$visited = [];
		$current = $candidate_parent;
		while ( $current > 0 ) {
			if ( isset( $visited[ $current ] ) ) {
				break;
			}
			$visited[ $current ] = true;

			if ( $current === $folder_id ) {
				return true;
			}

			$term = $this->repository->get_by_id( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
		}

		return false;
	}

	/**
	 * Trashed-guard для move()/create() ([internal]): проверяет
	 * целевого родителя И всю цепочку его предков — живая папка не должна прятаться под невидимым
	 * trashed-родителем/дедом и позже сноситься retention-cron'ом родителя. Родитель=0 (корень)
	 * всегда проходит (корень не может быть в корзине). Тот же обход, что is_descendant_of().
	 */
	private function has_trashed_ancestor(int $parent, string $taxonomy): bool {
		// [internal]: тот же cycle-guard, что is_descendant_of() — обход существующих данных
		// не гарантированно ацикличен (см. её докблок). false при обнаруженном цикле означает
		// "trashed-статус не может быть доказан через испорченную цепочку", тот же fail-open,
		// что уже возвращает конец метода при отсутствующем терме.
		$visited = [];
		$current = $parent;
		while ( $current > 0 ) {
			if ( isset( $visited[ $current ] ) ) {
				break;
			}
			$visited[ $current ] = true;

			if ( $this->repository->get_meta( $current, '_plathix_folder_trashed' ) ) {
				return true;
			}

			$term = $this->repository->get_by_id( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
		}

		return false;
	}

	private function next_position(int $current_id, int $parent, string $taxonomy): int {
		$max_position = 0;
		$sibling_ids  = $this->repository->get_children_ids( $parent, $taxonomy );

		// Batch-prime term meta cache; get_children_ids uses fields=ids which skips priming.
		if ( ! empty( $sibling_ids ) ) {
			update_termmeta_cache( $sibling_ids );
		}

		foreach ( $sibling_ids as $sibling_id ) {
			$sibling_id = (int) $sibling_id;
			if ( $sibling_id <= 0 || $sibling_id === $current_id ) {
				continue;
			}

			$position = (int) $this->repository->get_meta( $sibling_id, PLATHIX_TERM_POSITION );
			if ( $position > $max_position ) {
				$max_position = $position;
			}
		}

		return $max_position > 0 ? $max_position + 1000 : 1000;
	}
}
