<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\DbAdvisoryLock;

final class FolderRepository
{
	private const UNCATEGORIZED_SLUG = 'uncategorized';
	/** @var array<string, array<int, \WP_Term>> */
	private static array $runtime_cache = [];

	public static function clear_runtime_cache(): void {
		self::$runtime_cache = [];
	}

	/**
	 * Единый источник slug'ов системных папок, исключаемых из пользовательских списков/счёта/экспорта
	 * ([internal]). Заменяет разбросанные литералы ['uncategorized','plathix-trash']
	 * (FolderCountService SQL, Preset PROTECTED_SLUGS/export). Фильтр `plathix/folder/system_slugs` — крючок для
	 * будущего A7-выноса: когда создание trash-term уедет в Modules\Trash, модуль дорегистрирует свой slug.
	 *
	 * @return array<int, string>
	 */
	public static function system_slugs(): array {
		// Дефолт Free — только uncategorized (структурное ядро). trash-slug дописывает
		// Modules\Trash\Module через этот фильтр ([internal]): без модуля Free не
		// знает про trash-папку и не исключает несуществующий slug из счёта.
		/** @var array<int, string> $slugs */
		$slugs = (array) apply_filters( 'plathix/folder/system_slugs', [ self::UNCATEGORIZED_SLUG ] );

		return array_values( array_unique( array_map( 'strval', $slugs ) ) );
	}

	public static function ensure_system_terms(string $taxonomy): void {
		$uncategorized = get_term_by('slug', self::UNCATEGORIZED_SLUG, $taxonomy);

		if ( ! $uncategorized instanceof \WP_Term ) {
			$created = wp_insert_term(
				'Uncategorized',
				$taxonomy,
				[
					'slug'   => self::UNCATEGORIZED_SLUG,
					'parent' => FolderId::ROOT,
				]
			);
		}

		// Создание trash-term вынесено в Modules\Trash\Module::ensure_trash_terms ([internal],
		// канон A7): корзина = отключаемая надстройка-просмотр, её lifecycle-сущность создаёт модуль.
		// Здесь остаётся только uncategorized — структурное ядро (дом бесхозному файлу).

		self::clear_runtime_cache();
	}

	/** @return array<int, \WP_Term> */
	public function get_all(string $taxonomy): array {
		if ( isset(self::$runtime_cache[ $taxonomy ]) ) {
			return self::$runtime_cache[ $taxonomy ];
		}

		// [internal] (Слой 2): ленивое самовосстановление ПЕРЕД чтением дерева. Если
		// чужой фатал оборвал init до нашего Taxonomy::register — таксономия/системные термы
		// поднимаются здесь, а не остаются мёртвыми до перезагрузки/реактивации. Идемпотентно
		// и дёшево (guard-флаг $ready внутри). $lazy_recovery=true → путь чтения, не init.
		Taxonomy::ensure_ready( true );

		// CTAN-103: Free-self-heal поднимает только свою таксономию (plathix_folder).
		// Чужая (CPT-таксономия PRO) не зарегистрирована → событие владельцу ДО get_terms
		// (иначе первый запрос вернул бы [] несмотря на починку; WP_Error не кэшируется).
		// Семантика: уведомление владельцу чужой таксономии, НЕ включение Free-функциональности —
		// без подписчика no-op, get_terms честно вернёт WP_Error → []. Extension point
		// задекларирован в HookRegistry (CTAN-201).
		if ( PLATHIX_TAXONOMY !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			do_action( 'plathix/taxonomy/ensure_missing', $taxonomy );
		}

		$terms = get_terms(
			[
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
				'orderby' => 'name',
				'order' => 'ASC',
			]
		);

		if ( is_wp_error($terms) ) {
			return [];
		}
		/** @var array<int, \WP_Term> $terms Narrowed after is_wp_error() guard (see [internal] #6). */

		return self::$runtime_cache[ $taxonomy ] = $terms;
	}

	public function get_by_id(int $id, string $taxonomy): ?\WP_Term {
		$term = get_term($id, $taxonomy);

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return $term;
	}

	public function insert(string $name, int $parent, string $taxonomy): int|\WP_Error {
		global $wpdb;

		$lock_name = 'plx_i_' . md5(get_current_blog_id() . '|' . $taxonomy . '|' . $parent);
		$lock = DbAdvisoryLock::acquire($lock_name, 3);
		$must_lookup = ! $lock;

		if ( $must_lookup ) {
			// Прямой $wpdb-запрос вместо get_terms(suppress_filters:true) ([internal],
			// Plugin Check ERROR WPQueryParams.SuppressFilters — suppress_filters запрещён
			// безусловно, обоснование в комментарии его не снимает). Этот lookup внутри MySQL
			// advisory lock (GET_LOCK выше) обязан видеть точное состояние БД для
			// dedup-инварианта; сторонний pre_get_terms фильтр может исказить выборку и
			// сломать защиту от дублей папок при параллельном insert. Прямой SQL физически не
			// проходит через pre_get_terms, даёт тот же результат легальным для Plugin Check
			// способом. taxonomy обязателен в WHERE наравне с name/parent — термины разных
			// таксономий делят общее term-пространство WP, без этого условия dedup ложно
			// матчил бы термин из чужой таксономии.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedup lookup inside MySQL advisory lock; must see exact DB state, not cacheable by design
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
					INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s AND tt.parent = %d AND t.name = %s
					LIMIT 1",
					$taxonomy,
					$parent,
					$name
				)
			);

			if ( $existing_id !== null ) {
				return (int) $existing_id;
			}

			if ( ! $lock ) {
				return new \WP_Error('create_lock_failed', '', [ 'status' => 409 ]);
			}
		}

		try {
			/** @var array<string, int>|\WP_Error $result wp_insert_term() returns WP_Error in real WP; the analyzed stub omits it (see [internal] #6). */
			$result = wp_insert_term($name, $taxonomy, [ 'parent' => $parent ]);
			if ( is_wp_error($result) ) {
				/** @var \WP_Error $result Narrowed inside is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */
				if ( $result->get_error_code() === 'term_exists' ) {
					$term_id = (int) $result->get_error_data();
					$term = $this->get_by_id($term_id, $taxonomy);

					return $term?->term_id ? (int) $term->term_id : $result;
				}

				return $result;
			}
			/** @var array<string, int> $result Narrowed after is_wp_error() guard (see [internal] #6). */

			unset(self::$runtime_cache[ $taxonomy ]);

			return (int) $result['term_id'];
		} finally {
			if ( $lock ) {
				DbAdvisoryLock::release($lock_name);
			}
		}
	}

	/** @param array<string, mixed> $args */
	public function update(int $id, array $args, string $taxonomy): bool|\WP_Error {
		/** @var array<string, int>|\WP_Error $updated wp_update_term() returns WP_Error in real WP; the analyzed stub omits it (see [internal] #6). */
		$updated = wp_update_term($id, $taxonomy, $args);

		if ( is_wp_error($updated) ) {
			/** @var \WP_Error $updated Narrowed inside is_wp_error() guard (see [internal] #6). */
			return $updated;
		}

		unset(self::$runtime_cache[ $taxonomy ]);

		return true;
	}

	public function delete(int $id, string $taxonomy): bool {
		$deleted = wp_delete_term($id, $taxonomy);
		unset(self::$runtime_cache[ $taxonomy ]);

		return ! is_wp_error($deleted) && $deleted !== false;
	}

	public function get_meta(int $id, string $key): mixed {
		return get_term_meta($id, $key, true);
	}

	public function set_meta(int $id, string $key, mixed $value): void {
		update_term_meta($id, $key, $value);
		self::$runtime_cache = [];
	}

	public function delete_meta(int $id, string $key): void {
		delete_term_meta($id, $key);
		self::$runtime_cache = [];
	}

	/**
	 * ID папок, помеченных в корзину (soft-trash, [internal]). Одна выборка с meta-JOIN (не N+1).
	 * Источник И для исключения из живого дерева (FolderCountService), И для списка корзины папок.
	 *
	 * @param string $order_meta_key Пусто (по умолчанию) — старое поведение: фильтр через
	 *        top-level meta_key/meta_value, порядок не задан (WP default `orderby=name`).
	 *        Непустое имя meta-ключа ([internal], [internal]) — сортирует по числовому
	 *        значению ЭТОЙ меты по возрастанию (самые старые trash_time первыми), а фильтр
	 *        `_plathix_folder_trashed=1` уезжает во вложенный meta_query: `orderby=meta_value_num`
	 *        в WP_Term_Query читает top-level `meta_key` как источник значения для сортировки —
	 *        top-level `meta_key` не может одновременно быть и полем фильтра, и полем сортировки
	 *        для двух разных meta-ключей. Гарантирует, что вызывающий, ограничивающий результат
	 *        срезом (`array_slice`), берёт самые просроченные записи первыми, а не первые по
	 *        алфавиту имени папки.
	 * @return array<int, int>
	 */
	public function get_trashed_ids(string $taxonomy, string $order_meta_key = ''): array {
		if ( '' === $order_meta_key ) {
			$args = [
				'taxonomy'   => $taxonomy,
				'fields'     => 'ids',
				'hide_empty' => false,
				'meta_key'   => '_plathix_folder_trashed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta filter, ID set is cached upstream by FolderCountService versioned key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see meta_key note
			];
		} else {
			$args = [
				'taxonomy'   => $taxonomy,
				'fields'     => 'ids',
				'hide_empty' => false,
				'meta_key'   => $order_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- orderby target key, not a filter; filter clause is in meta_query below
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filter clause, separate from the orderby meta_key above
					[
						'key'     => '_plathix_folder_trashed',
						'value'   => '1',
						'compare' => '=',
					],
				],
			];
		}

		$terms = get_terms( $args );

		if ( is_wp_error($terms) ) {
			return [];
		}
		/** @var array<int, int|string> $terms Narrowed after is_wp_error() guard. */

		return array_map('intval', $terms);
	}

	/** @return array<int, int> */
	public function get_children_ids(int $parent_id, string $taxonomy): array {
		$terms = get_terms(
			[
				'taxonomy' => $taxonomy,
				'parent' => $parent_id,
				'fields' => 'ids',
				'hide_empty' => false,
			]
		);

		if ( is_wp_error($terms) ) {
			return [];
		}
		/** @var array<int, int|string> $terms Narrowed after is_wp_error() guard (see [internal] #6). */

		return array_map('intval', $terms);
	}

	public function update_parent(int $id, int $parent_id, string $taxonomy): void {
		global $wpdb;

		$term = $this->get_by_id($id, $taxonomy);
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		/** @var \WP_Term&object{term_taxonomy_id:int} $term -- phpstan-wordpress stub omits declared WP_Term properties */
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- UPDATE of a term row this plugin owns; nothing to cache on a write
			$wpdb->term_taxonomy,
			[ 'parent' => $parent_id ],
			[ 'term_taxonomy_id' => (int) $term->term_taxonomy_id ],
			[ '%d' ],
			[ '%d' ]
		);

		clean_term_cache([ $id ], $taxonomy);
		unset(self::$runtime_cache[ $taxonomy ]);
	}

	/**
	 * @internal Вызывается ТОЛЬКО из FolderTreeService::reparent_children, которая работает
	 * под per-taxonomy structure-локом delete_recursive (G2, [internal]). Собственного лока
	 * не берёт намеренно — иначе вложенность delete→reparent→bulk дала бы self-deadlock на
	 * нереентрантном option-fallback. Не вызывать напрямую в обход FolderTreeService.
	 *
	 * Раньше здесь была МЁРТВАЯ проверка plathix_structure_locked_ (option читался, но никем
	 * не писался) — убрана, реальную блокировку обеспечивает внешний structure-лок.
	 */
	public function bulk_update_parent(int $old_parent, int $new_parent, string $taxonomy): int|\WP_Error {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk write over term rows this plugin owns; nothing to cache on a write
			$wpdb->prepare(
				"UPDATE {$wpdb->term_taxonomy} SET parent = %d WHERE parent = %d AND taxonomy = %s",
				$new_parent,
				$old_parent,
				$taxonomy
			)
		);

		if ( false === $result ) {
			return new \WP_Error('db_error', $wpdb->last_error ?: 'Database query failed.');
		}

		unset(self::$runtime_cache[ $taxonomy ]);

		return (int) $result;
	}

	public function get_uncategorized_term_id(string $taxonomy): int {
		$term = get_term_by('slug', self::UNCATEGORIZED_SLUG, $taxonomy);

		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}

		return (int) $term->term_id;
	}

	public function is_uncategorized_folder(int $folder_id, string $taxonomy): bool {
		if ( $folder_id <= 0 ) {
			return false;
		}

		$term = $this->get_by_id($folder_id, $taxonomy);

		return $term instanceof \WP_Term && $term->slug === self::UNCATEGORIZED_SLUG;
	}

	/**
	 * Returns the subset of $ids that are direct parents of at least one child term.
	 * One SQL query for all IDs — no N+1, no full-tree fetch.
	 *
	 * [internal]: a parent whose only children are soft-trashed (HiddenFolders::ids(),
	 * `_plathix_folder_trashed`) must NOT count as "has children" — those children are
	 * invisible in the live tree (get_all_cached() already excludes them), so a caller
	 * showing an expand arrow for a node with only trashed children would open onto an
	 * empty view. The original query only excluded IDs FROM the candidate parent set
	 * ($ids/$child_ids, already filtered upstream in get_children()); it never excluded a
	 * CHILD term (the row's own term_id, matched against `parent`) being trashed — a parent
	 * whose sole child is trashed still had a term_taxonomy row with that parent value, so
	 * it was counted as "has children" regardless of the child's visibility.
	 *
	 * @param int[]  $ids
	 * @return int[]
	 */
	public function get_parent_ids_that_have_children(array $ids, string $taxonomy): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return [];
		}

		$safe_ids     = implode( ',', array_map( 'intval', $ids ) );
		$taxonomy_esc = esc_sql( $taxonomy );

		$hidden_ids = HiddenFolders::ids( $taxonomy );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $hidden_ids come from HiddenFolders::ids(), which returns int term IDs from the same taxonomy's own termmeta lookup, not raw external input.
		$exclude_children_sql = $hidden_ids === []
			? ''
			: ' AND term_id NOT IN (' . implode( ',', array_map( 'intval', $hidden_ids ) ) . ')';
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- id list is intval-mapped per element and the taxonomy is bound via %s; table names are $wpdb properties
		$rows = $wpdb->get_col(
			"SELECT DISTINCT parent
			   FROM {$wpdb->term_taxonomy}
			  WHERE taxonomy = '{$taxonomy_esc}'
			    AND parent IN ({$safe_ids}){$exclude_children_sql}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Returns ancestor term IDs for $id, ordered from immediate parent to root.
	 * Returns an empty array if $id is a root term.
	 *
	 * @return int[]
	 */
	public function get_ancestry_ids(int $id, string $taxonomy): array {
		$ancestors = [];
		$visited   = [];
		$current   = $id;

		while ( true ) {
			$term = $this->get_by_id( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$parent = (int) $term->parent;
			if ( $parent <= 0 || isset( $visited[ $parent ] ) ) {
				break;
			}

			$ancestors[]        = $parent;
			$visited[ $parent ] = true;
			$current            = $parent;
		}

		return $ancestors;
	}
}
