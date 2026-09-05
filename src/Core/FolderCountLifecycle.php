<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единственный владелец per-file дельт рекурсивного счётчика папок
 * (`_plathix_folder_count_recursive`) — [internal] ([internal]).
 *
 * До этого пакета дельты слали 6 разбросанных точек (Upload, FolderAssignmentService ×2,
 * MediaDeleteService ×2, Trash\Module), каждая сама помнила visibility-предикат и
 * re-entrancy — и каждый фикс пропускал часть инвариантов (#692 → #794/#798/#802).
 * Теперь дельты файловых событий порождает ТОЛЬКО этот подписчик штатных WP-хуков:
 * одно событие = одна дельта, предикат счётности — в одной точке.
 *
 * Агрегатные дельты папочных операций (reparent в FolderTreeService::move, удаление
 * терма в delete_recursive_body) остаются явными у владельцев дерева: на reparent
 * per-file событий в WP нет вовсе, а wp_delete_term() стреляет их per-file (core-цикл
 * wp_set_object_terms) — владелец оборачивает удаление в {@see suppress()}, оставляя
 * свою константную агрегатную дельту вместо O(files) событийных.
 *
 * [internal] ([internal], живой остаток): дельты набора термов ведутся ПЕР-RELATION
 * событиями `added_term_relationship`/`deleted_term_relationships` вместо diff по аргументам
 * финального хука `set_object_terms`. Причина замены — diff (`$tt_ids`/`$old_tt_ids`)
 * структурно не мог покрыть два случая: `append: true` (core отдаёт `$old_tt_ids=[]`, а
 * `$tt_ids` включает уже привязанные термы — наивный diff даёт ложный +1) и прямой
 * `wp_remove_object_terms()` (не идёт через `set_object_terms` вообще). WP core (проверено
 * по `wp-includes/taxonomy.php`) даёт честный per-relation сигнал: `added_term_relationship`
 * стреляет ТОЛЬКО когда relation физически новая (после guard на существующую, до INSERT
 * которого — no-op), одинаково при append и без; `deleted_term_relationships` — batch-хук
 * после факта DELETE, стреляющий и на прямой `wp_remove_object_terms()`, и на внутренний
 * вызов из `wp_set_object_terms()` (не-append с удалением) — один и тот же путь, второго
 * слоя учёта не возникает, потому что `set_object_terms`-хук для дельт больше не используется.
 */
final class FolderCountLifecycle
{
	/**
	 * Re-entrancy/suppression-флаг: владелец агрегатной операции (delete_recursive_body)
	 * подавляет per-file дельты своего каскада, оставляя одну явную агрегатную.
	 */
	private static bool $suppressed = false;

	/**
	 * Кэш конвертации term_taxonomy_id => term_id в рамках запроса — bulk-операция между
	 * одними и теми же папками амортизируется до одного SELECT на весь bulk
	 * (perf-условие пакета: дельты собираются из аргументов хука без per-item SELECT'ов).
	 *
	 * @var array<int, int>
	 */
	private static array $tt_to_term = [];

	/**
	 * Стэш «пары удаления» (FCCOLD-инвариант, concurrency-условие пакета): термы записи
	 * читаются на pre-mutation хуке (delete_attachment / before_delete_post — на
	 * post-mutation deleted_post relations уже сняты ядром), а дельта применяется ТОЛЬКО
	 * на deleted_post — после фактической мутации БД. Иначе cold-seed предка на
	 * pre-хуке посчитал бы ещё-живой файл и съел дельту по FCCOLD → вечный +1; а warm
	 * −1 на pre-хуке при сорвавшемся wp_delete_post() занижал бы счётчик живого файла.
	 * Сорванное удаление: deleted_post не стреляет → запись стэша не применяется
	 * (умирает с концом запроса — состояние per-request, как и весь static этого класса).
	 *
	 * @var array<int, array{terms: array<int, int>, taxonomy: string}>
	 */
	private static array $pending_delete_deltas = [];

	public function __construct(private readonly FolderCountService $count_service)
	{
	}

	/**
	 * Подписки на lifecycle-хуки. accepted_args=6 для set_object_terms обязателен
	 * (WP по умолчанию передаёт колбэку один аргумент; без явного числа $old_tt_ids
	 * не придёт — правило [internal]).
	 */
	public function register(): void
	{
		// [internal]: per-relation дельты (см. докблок класса) — заменяют прежнюю
		// diff-подписку на set_object_terms. accepted_args=3 для обоих: added_term_relationship
		// ($object_id, $tt_id, $taxonomy), deleted_term_relationships ($object_id, $tt_ids, $taxonomy).
		add_action( 'added_term_relationship', [ $this, 'on_added_term_relationship' ], 10, 3 );
		add_action( 'deleted_term_relationships', [ $this, 'on_deleted_term_relationships' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'on_trashed_post' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'on_untrashed_post' ], 10, 1 );
		// Пары удаления (стэш -> apply, см. $pending_delete_deltas). Для attachment
		// pre-хук — delete_attachment: before_delete_post для вложений НЕ стреляет вовсе
		// (wp_delete_post() делегирует в wp_delete_attachment() ДО do_action, а внутри
		// wp_delete_attachment() этого хука нет — факт WP core, senior-dev скептик пакета).
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 1 );
		// Visibility-переходы ([internal], временная асимметрия предиката): генераторы
		// ставят скрывающую мету ПОСЛЕ создания attachment (Elementor пишет
		// `_elementor_is_screenshot` через update_post_meta уже после add_attachment) —
		// файл входил в счётчик как visible (+1), а все последующие события видели его
		// hidden (−0): вечный +1 на каждый такой файл. Событие изменения меты — часть
		// контракта владельца дельт. accepted_args=4: meta_key — третий аргумент.
		add_action( 'added_post_meta', [ $this, 'on_added_post_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'on_deleted_post_meta' ], 10, 4 );
	}

	/**
	 * Выполняет $op с подавленными per-file дельтами этого подписчика. Re-entrant:
	 * вложенный suppress() не снимает флаг раньше времени (восстанавливается прежнее
	 * значение в finally, а не безусловный false).
	 */
	public static function suppress(callable $op): mixed
	{
		$prev             = self::$suppressed;
		self::$suppressed = true;
		try {
			return $op();
		} finally {
			self::$suppressed = $prev;
		}
	}

	/**
	 * Новая relation добавлена (физически — после guard на уже существующую, до INSERT
	 * которого WP core не доходит вовсе): +1. Стреляет одинаково при `append: true` и без —
	 * WP core строит этот цикл из аргумента `$terms` ДО ветвления на append, поэтому здесь
	 * не нужен спецкейс на append (закрывает append-ограничение прежней diff-модели).
	 *
	 * @param int|string $object_id
	 * @param int|string $tt_id     term_taxonomy_id новой relation.
	 * @param string     $taxonomy
	 */
	public function on_added_term_relationship($object_id, $tt_id, $taxonomy): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$taxonomy = (string) $taxonomy;
		if ( $taxonomy !== PLATHIX_TAXONOMY && ! str_starts_with( $taxonomy, PLATHIX_TAX_PREFIX ) ) {
			return;
		}

		$object_id = (int) $object_id;
		if ( get_post_type( $object_id ) !== Taxonomy::post_type_for_taxonomy( $taxonomy ) ) {
			return;
		}
		if ( ! $this->is_countable( $object_id ) ) {
			return;
		}

		[ $term_id ] = $this->tt_ids_to_term_ids( [ (int) $tt_id ], $taxonomy );
		$this->count_service->increment_recursive_chain( $term_id, $taxonomy, +1 );
	}

	/**
	 * Relations физически удалены (batch, после факта DELETE) — покрывает и прямой
	 * `wp_remove_object_terms()`, и внутренний вызов из `wp_set_object_terms()` (не-append
	 * с удалением) через один и тот же путь: `set_object_terms`-хук для дельт не используется,
	 * второго слоя учёта нет (закрывает remove-ограничение прежней diff-модели).
	 *
	 * Guard существования поста ДО post_type-проверки (не после): для физически
	 * несуществующего объекта (единственный легитимный вызывающий — OrphanCleanupJobRunner,
	 * обёрнутый в suppress()) `get_post_type()` возвращает `false`, что не равно ни одному
	 * post_type — событие уже пропускалось бы верно этим guard'ом, но explicit-проверка
	 * читается честнее, чем полагаться на побочный эффект типового сравнения.
	 *
	 * @param int|string        $object_id
	 * @param array<int|string> $tt_ids   term_taxonomy_id удалённых relations.
	 * @param string            $taxonomy
	 */
	public function on_deleted_term_relationships($object_id, $tt_ids, $taxonomy): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$taxonomy = (string) $taxonomy;
		if ( $taxonomy !== PLATHIX_TAXONOMY && ! str_starts_with( $taxonomy, PLATHIX_TAX_PREFIX ) ) {
			return;
		}

		$object_id = (int) $object_id;
		if ( null === get_post( $object_id ) ) {
			// Пост физически не существует — SQL-истина (batch_counts()) никогда не считала
			// этот object_id, дельта была бы заведомо ложной. Легитимные вызывающие для этого
			// случая (OrphanCleanupJobRunner) уже оборачивают вызов в suppress() — сюда
			// доходит только неожиданный сторонний путь, и honest no-op безопаснее дельты.
			return;
		}
		if ( get_post_type( $object_id ) !== Taxonomy::post_type_for_taxonomy( $taxonomy ) ) {
			return;
		}
		if ( ! $this->is_countable( $object_id ) ) {
			return;
		}

		$tt_ids = array_map( 'intval', (array) $tt_ids );
		if ( $tt_ids === [] ) {
			return;
		}
		foreach ( $this->tt_ids_to_term_ids( $tt_ids, $taxonomy ) as $term_id ) {
			$this->count_service->increment_recursive_chain( $term_id, $taxonomy, -1 );
		}
	}

	/**
	 * Файл ушёл в корзину: −1 всем его папкам (relation при trash не рвётся, но trashed
	 * исключён из SQL-истины batch_counts по status-предикату). Статус «до события»
	 * гарантирован семантикой хука (trashed_post стреляет только на переходе в trash),
	 * поэтому статусная часть предиката здесь не читается — только meta-часть.
	 *
	 * Покрывает ВСЕ пути trash одинаково: Plathix bulk_trash, нативную «В корзину» из
	 * upload.php/грида, WP-CLI, каскад «папка в корзину вместе с файлами» — прежний
	 * маркер MediaDeleteService::is_processing() и его слепые зоны (#794) удалены пакетом.
	 *
	 * @param int|string $post_id
	 */
	public function on_trashed_post($post_id): void
	{
		$this->apply_trash_transition_delta( (int) $post_id, -1 );
	}

	/**
	 * Файл восстановлен из корзины: +1 всем его текущим папкам. bulk_restore() может
	 * затем перенаправить файл в другую папку — это отдельная мутация wp_set_object_terms,
	 * её diff (−старая/+target) обработает on_set_object_terms(); сумма корректна и при
	 * WP_Error на переназначении (файл остаётся в прежней папке с уже применённым +1).
	 *
	 * @param int|string $post_id
	 */
	public function on_untrashed_post($post_id): void
	{
		$this->apply_trash_transition_delta( (int) $post_id, +1 );
	}

	/**
	 * Общая часть trashed_post/untrashed_post: attachment-ветка (не-attachment записями
	 * владеет FolderCountService::adjust_for_post — подписки в Plugin.php).
	 */
	private function apply_trash_transition_delta(int $post_id, int $delta): void
	{
		if ( self::$suppressed ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		if ( ! AttachmentVisibility::is_visible_by_meta( $post_id ) ) {
			// Generator-hidden файл не участвует в счётчике ни в одном направлении —
			// зеркальный гейт к upload-стороне (закрывает asymmetry [internal]).
			return;
		}

		$taxonomy   = PLATHIX_TAXONOMY;
		$folder_ids = $this->read_folder_ids( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->count_service->increment_recursive_chain( $folder_id, $taxonomy, $delta );
		}
		$this->count_service->invalidate( $taxonomy );
	}

	/**
	 * Pre-хук удаления attachment: стэш термов для применения на deleted_post.
	 * Guard `post_status === 'trash'` — удаление ИЗ корзины не даёт второго −1: файл уже
	 * вычтен при trashed_post (тот же класс guard'а, что нёс прежний
	 * adjust_for_deleted_post для не-attachment; двойной wp_trash_post, эскалирующий в
	 * permanent — [internal] — приходит сюда тем же trash-статусом и так же скипается).
	 *
	 * @param int|string $post_id
	 */
	public function on_delete_attachment($post_id): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		if ( 'trash' === (string) get_post_status( $post_id ) ) {
			return;
		}
		if ( ! AttachmentVisibility::is_visible_by_meta( $post_id ) ) {
			return;
		}

		$folder_ids = $this->read_folder_ids( $post_id, PLATHIX_TAXONOMY );
		if ( $folder_ids === [] ) {
			return;
		}
		self::$pending_delete_deltas[ $post_id ] = [
			'terms'    => $folder_ids,
			'taxonomy' => PLATHIX_TAXONOMY,
		];
	}

	/**
	 * Pre-хук удаления НЕ-attachment записи (post/page/CPT с PRO-таксономией папок):
	 * стэш термов для применения на deleted_post. Заменяет прямой
	 * adjust_for_deleted_post-вызов (Plugin.php до [internal]): тот применял −1 прямо
	 * на before_delete_post — файл ещё жив в SQL, cold-seed предка включал его и съедал
	 * дельту по FCCOLD → вечный +1 (concurrency-скептик пакета, п.3).
	 *
	 * @param int|string $post_id
	 * @param mixed      $post    WP_Post от ядра (accepted_args=2) — статус без второго чтения.
	 */
	public function on_before_delete_post($post_id, $post = null): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$post_id   = (int) $post_id;
		$post_type = (string) get_post_type( $post_id );
		if ( '' === $post_type || 'attachment' === $post_type ) {
			return;
		}

		$taxonomy = TaxonomyResolver::fromPostType( $post_type );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		// Удаление из корзины и удаление мимо корзины приходят в один хук; первое уже
		// было учтено при уходе в корзину (adjust_for_post на trashed_post) — второй
		// декремент того же объекта запрещён.
		$status = $post instanceof \WP_Post ? (string) $post->post_status : (string) get_post_status( $post_id );
		if ( 'trash' === $status ) {
			return;
		}

		$folder_ids = $this->read_folder_ids( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}
		self::$pending_delete_deltas[ $post_id ] = [
			'terms'    => $folder_ids,
			'taxonomy' => $taxonomy,
		];
	}

	/**
	 * Post-мутационное применение стэша пары удаления: запись фактически удалена из БД —
	 * cold-seed предка её уже не видит, FCCOLD-инвариант («seed включает мутацию, дельта
	 * поверх seed не применяется») сохранён и на delete-пути.
	 *
	 * @param int|string $post_id
	 */
	public function on_deleted_post($post_id): void
	{
		$post_id = (int) $post_id;
		$pending = self::$pending_delete_deltas[ $post_id ] ?? null;
		if ( null === $pending ) {
			return;
		}
		unset( self::$pending_delete_deltas[ $post_id ] );

		if ( self::$suppressed ) {
			// Агрегатная операция владельца дерева покрывает эту дельту явно.
			return;
		}

		foreach ( $pending['terms'] as $folder_id ) {
			$this->count_service->increment_recursive_chain( $folder_id, $pending['taxonomy'], -1 );
		}
		$this->count_service->invalidate( $pending['taxonomy'] );
	}

	/**
	 * Добавлен exclude-ключ (файл стал generator-hidden): если до этого файл был счётным
	 * (visible по статусу и по ОСТАЛЬНЫМ ключам — спорный уже записан, хук post-mutation)
	 * — вычесть его из папок. `updated_post_meta` намеренно не слушается: exclude-предикат
	 * проверяет НАЛИЧИЕ ключа (metadata_exists), не значение — смена значения переход не
	 * образует.
	 *
	 * @param int|string        $meta_id
	 * @param int|string        $object_id
	 * @param string            $meta_key
	 * @param mixed             $meta_value
	 */
	public function on_added_post_meta($meta_id, $object_id, $meta_key, $meta_value = null): void
	{
		$this->apply_visibility_transition_delta( (int) $object_id, (string) $meta_key, -1 );
	}

	/**
	 * Удалён exclude-ключ (файл снова видим): если по остальным ключам и статусу файл
	 * счётный — вернуть его в папки. Хук стреляет ПОСЛЕ удаления меты — обычный
	 * is_visible_by_meta уже честен, но общий helper с except-ключом корректен в обе
	 * стороны (удалённый ключ metadata_exists уже не видит).
	 *
	 * @param int[]|int|string  $meta_ids
	 * @param int|string        $object_id
	 * @param string            $meta_key
	 * @param mixed             $meta_value
	 */
	public function on_deleted_post_meta($meta_ids, $object_id, $meta_key, $meta_value = null): void
	{
		$this->apply_visibility_transition_delta( (int) $object_id, (string) $meta_key, +1 );
	}

	/**
	 * Общая часть added/deleted_post_meta. Guard по ключу — ПЕРВОЙ строкой (хуки мет
	 * глобальные, стреляют на каждую мету сайта; чужой ключ обязан стоить 0 SQL —
	 * exclude_meta_keys() резолвится in-memory через фильтр, без запросов).
	 */
	private function apply_visibility_transition_delta(int $post_id, string $meta_key, int $delta): void
	{
		if ( self::$suppressed ) {
			return;
		}
		if ( ! in_array( $meta_key, AttachmentVisibility::exclude_meta_keys(), true ) ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		if ( ! AttachmentVisibility::is_visible_status( (string) get_post_status( $post_id ) ) ) {
			// Файл в корзине/auto-draft и так вне счёта — переход меты ничего не меняет.
			return;
		}
		if ( ! AttachmentVisibility::is_visible_by_meta_except( $post_id, $meta_key ) ) {
			// Другой exclude-ключ держит файл hidden независимо от этого события.
			return;
		}

		$taxonomy   = PLATHIX_TAXONOMY;
		$folder_ids = $this->read_folder_ids( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->count_service->increment_recursive_chain( $folder_id, $taxonomy, $delta );
		}
		$this->count_service->invalidate( $taxonomy );
	}

	/**
	 * Папки файла на момент события. Сначала прогретый WP term cache
	 * (get_object_term_cache — пост загружен ядром до хука, кэш обычно тёплый), затем
	 * wp_get_object_terms как fallback — perf-условие пакета: не платить SQL там, где
	 * object cache уже держит ответ.
	 *
	 * @return array<int, int>
	 */
	private function read_folder_ids(int $post_id, string $taxonomy): array
	{
		if ( function_exists( 'get_object_term_cache' ) ) {
			$cached = get_object_term_cache( $post_id, $taxonomy );
			if ( is_array( $cached ) ) {
				return array_values( array_map( static fn (\WP_Term $t): int => (int) $t->term_id, $cached ) );
			}
		}

		$folder_ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $folder_ids ) ) {
			return [];
		}

		return array_map( 'intval', (array) $folder_ids );
	}

	/**
	 * Полный предикат счётности — зеркало SQL-истины FolderCountCalculator::batch_counts()
	 * (status-денylist + meta-денylist; post_type сверяется вызывающим кодом по таксономии).
	 * Для не-attachment записей meta-часть тривиально true (exclude-ключей на них нет) —
	 * ветвление по типу не требуется.
	 */
	private function is_countable(int $post_id): bool
	{
		return AttachmentVisibility::is_visible_status( (string) get_post_status( $post_id ) )
			&& AttachmentVisibility::is_visible_by_meta( $post_id );
	}

	/**
	 * Конвертация term_taxonomy_id → term_id (равенство term_id == tt_id после split-terms
	 * не гарантировано). Один SELECT на непокрытые кэшем id за вызов; static-кэш живёт до
	 * конца запроса — `on_added_term_relationship` вызывается per-relation (по одному tt_id),
	 * поэтому bulk-присвоение амортизируется кэшем между вызовами на РАЗНЫЕ id той же папки
	 * (та же папка на N файлах = 1 SELECT на первый файл, 0 на остальные), но не спасает
	 * первый SELECT на каждый НОВЫЙ id в рамках одного bulk (N новых папок = N отдельных
	 * SELECT вместо 1 батчевого — известный perf-trade-off этого пути, не в hot path
	 * рендера, только на мутациях).
	 *
	 * @param array<int, int> $tt_ids
	 * @return array<int, int>
	 */
	private function tt_ids_to_term_ids(array $tt_ids, string $taxonomy): array
	{
		$missing = array_values( array_filter( $tt_ids, static fn (int $tt): bool => ! isset( self::$tt_to_term[ $tt ] ) ) );

		if ( $missing !== [] ) {
			global $wpdb;
			$id_list = implode( ',', array_map( 'intval', $missing ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $id_list is intval'd; core has no batch tt_id->term_id API, mirror of FolderCountCalculator's own IN-list form
			$rows = $wpdb->get_results( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$id_list})" );
			foreach ( (array) $rows as $row ) {
				self::$tt_to_term[ (int) $row->term_taxonomy_id ] = (int) $row->term_id;
			}
			// Непокрытые строки (гонка с удалением терма): считаем tt_id == term_id как
			// последний честный fallback — хуже пропустить дельту совсем, чем адресовать
			// цепочку по прямому маппингу дефолтной схемы WP (split-terms давно завершён,
			// в ней tt_id == term_id для всех новых термов).
			foreach ( $missing as $tt ) {
				self::$tt_to_term[ $tt ] ??= $tt;
			}
		}

		return array_values( array_map( static fn (int $tt): int => self::$tt_to_term[ $tt ], $tt_ids ) );
	}

	/**
	 * Сброс static-состояния между тестами (кэш маппинга и suppression-флаг).
	 * Прод-кода не касается — состояние живёт ровно один запрос.
	 */
	public static function reset_runtime_state(): void
	{
		self::$tt_to_term            = [];
		self::$suppressed            = false;
		self::$pending_delete_deltas = [];
	}
}
