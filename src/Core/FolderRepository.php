<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Logger;

final class FolderRepository
{
	private const UNCATEGORIZED_SLUG = 'uncategorized';
	/** @var array<string, array<int, \WP_Term>> */
	private static array $runtime_cache = [];

	public static function clearRuntimeCache(): void {
		self::$runtime_cache = [];
	}

	/**
	 * @return array<int, string>
	 */

	public static function systemSlugs(): array {

		/** @var array<int, string> $slugs */
		$slugs = (array) apply_filters( 'plathix/folder/system_slugs', [ self::UNCATEGORIZED_SLUG ] );

		return array_values( array_unique( array_map( 'strval', $slugs ) ) );
	}

	public static function ensureSystemTerms(string $taxonomy): void {
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

			if ( is_wp_error( $created ) ) {
				Logger::error( 'folder_repository_ensure_uncategorized_term_failed', [ 'taxonomy' => $taxonomy ] );
			}
		}


		self::clearRuntimeCache();
	}

	/** @return array<int, \WP_Term> */
	public function getAll(string $taxonomy): array {
		if ( isset(self::$runtime_cache[ $taxonomy ]) ) {
			return self::$runtime_cache[ $taxonomy ];
		}

		Taxonomy::ensureReady( true );

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
		/**
		 * @var array<int, \WP_Term> $terms
		 */


		return self::$runtime_cache[ $taxonomy ] = $terms;
	}

	public function getById(int $id, string $taxonomy): ?\WP_Term {
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
			/**
			 * @var array<string, int>|\WP_Error $result
			 */

			$result = wp_insert_term($name, $taxonomy, [ 'parent' => $parent ]);
			if ( is_wp_error($result) ) {
				/**
				 * @var \WP_Error $result
				 */

				if ( $result->get_error_code() === 'term_exists' ) {
					$term_id = (int) $result->get_error_data();
					$term = $this->getById($term_id, $taxonomy);

					return $term?->term_id ? (int) $term->term_id : $result;
				}

				return $result;
			}
			/**
			 * @var array<string, int> $result
			 */


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
		/**
		 * @var array<string, int>|\WP_Error $updated
		 */

		$updated = wp_update_term($id, $taxonomy, $args);

		if ( is_wp_error($updated) ) {
			/**
			 * @var \WP_Error $updated
			 */

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

	public function getMeta(int $id, string $key): mixed {
		return get_term_meta($id, $key, true);
	}

	public function setMeta(int $id, string $key, mixed $value): void {
		update_term_meta($id, $key, $value);
		self::$runtime_cache = [];
	}

	public function deleteMeta(int $id, string $key): void {
		delete_term_meta($id, $key);
		self::$runtime_cache = [];
	}

	/**
	 * @param string $order_meta_key
	 * @return array<int, int>
	 */

	public function getTrashedIds(string $taxonomy, string $order_meta_key = ''): array {
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
	public function getChildrenIds(int $parent_id, string $taxonomy): array {
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
		/**
		 * @var array<int, int|string> $terms
		 */


		return array_map('intval', $terms);
	}

	public function updateParent(int $id, int $parent_id, string $taxonomy): void {
		global $wpdb;

		$term = $this->getById($id, $taxonomy);
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
	 * @internal
	 */

	public function bulkUpdateParent(int $old_parent, int $new_parent, string $taxonomy): int|\WP_Error {
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

	public function getUncategorizedTermId(string $taxonomy): int {
		$term = get_term_by('slug', self::UNCATEGORIZED_SLUG, $taxonomy);

		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}

		return (int) $term->term_id;
	}

	public function isUncategorizedFolder(int $folder_id, string $taxonomy): bool {
		if ( $folder_id <= 0 ) {
			return false;
		}

		$term = $this->getById($folder_id, $taxonomy);

		return $term instanceof \WP_Term && $term->slug === self::UNCATEGORIZED_SLUG;
	}

	/**
	 * @param int[]  $ids
	 * @return int[]
	 */

	public function getParentIdsThatHaveChildren(array $ids, string $taxonomy): array {
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
	public function getAncestryIds(int $id, string $taxonomy): array {
		$ancestors = [];
		$visited   = [];
		$current   = $id;

		while ( true ) {
			$term = $this->getById( $current, $taxonomy );
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
