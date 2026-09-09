<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\Keys;

final class FolderCountService
{
	private const CACHE_TTL = 300;
	private const ALL_FILES_ID = FolderId::ROOT;

	private const RECURSIVE_COUNT_META_KEY = '_plathix_folder_count_recursive';
	/** @var array<string, bool> */
	private array $bulk_invalidations = [];
	private readonly FolderCountCalculator $calculator;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly Cache $cache
	) {

		$this->calculator = new FolderCountCalculator();
	}

	public function getCount(int $folder_id, string $taxonomy): ?int {
		$class = $this->classifyFolder( $folder_id, $taxonomy );
		if ( 'trash' === $class ) {
			return $this->calculator->trashItemsCount();
		}
		if ( 'uncategorized' === $class ) {
			return $this->calculator->uncategorizedItemsCount( $taxonomy );
		}

		$key = $this->cache->versionedKey( 'folders_' . $taxonomy, 'count_' . $folder_id );
		$cached = $this->cache->get( $key );
		if ( is_int( $cached ) ) {
			return $cached;
		}

		$lock_key = Keys::lock( 'recount_' . get_current_blog_id() . '_' . $taxonomy . '_' . $folder_id );
		if ( ! wp_cache_add( $lock_key, 1, 'plathix', 5 ) ) {
			return null;
		}

		try {
			// Use direct SQL (same as get_batch_counts) to count only items
			// directly in this folder — never including descendants.
			// WordPress's $term->count on hierarchical taxonomies aggregates
			// children, which gives misleading numbers.
			$counts = $this->calculator->batchCounts( [ $folder_id ], $taxonomy );
			if ( null === $counts ) {

				return null;
			}
			$count = $counts[ $folder_id ] ?? 0;
			$this->cache->set( $key, $count, self::CACHE_TTL );

			return $count;
		} finally {
			wp_cache_delete( $lock_key, 'plathix' );
		}
	}

	/**
	 * @return 'trash'|'uncategorized'|'normal'
	 */

	private function classifyFolder(int $folder_id, string $taxonomy): string {
		if ( $folder_id > 0 && $folder_id === TrashFolder::id( $taxonomy ) ) {
			return 'trash';
		}

		if ( $this->repository->isUncategorizedFolder( $folder_id, $taxonomy ) ) {
			return 'uncategorized';
		}

		return 'normal';
	}

	/**
	 * @param int[] $folder_ids
	 * @return array<int,int>
	 */

	public function getCountsFor(array $folder_ids, string $taxonomy): array {
		$hidden = array_flip( HiddenFolders::ids( $taxonomy ) );
		$result = [];
		$normal_ids = [];

		foreach ( $folder_ids as $folder_id ) {
			$folder_id = (int) $folder_id;
			if ( $folder_id <= 0 || isset( $hidden[ $folder_id ] ) ) {
				continue;
			}

			$class = $this->classifyFolder( $folder_id, $taxonomy );
			if ( 'trash' === $class ) {
				$result[ $folder_id ] = $this->calculator->trashItemsCount();
			} elseif ( 'uncategorized' === $class ) {

				$result[ $folder_id ] = $this->calculator->uncategorizedItemsCount( $taxonomy ) ?? 0;
			} else {
				$normal_ids[] = $folder_id;
			}
		}

		if ( $normal_ids !== [] ) {

			$keys_before_sql = [];
			foreach ( $normal_ids as $folder_id ) {
				$keys_before_sql[ $folder_id ] = $this->cache->versionedKey( 'folders_' . $taxonomy, 'count_' . $folder_id );
			}

			$counts = $this->calculator->batchCounts( $normal_ids, $taxonomy );
			foreach ( $normal_ids as $folder_id ) {

				if ( null === $counts ) {
					$result[ $folder_id ] = 0;
					continue;
				}

				$count = $counts[ $folder_id ] ?? 0;
				$result[ $folder_id ] = $count;

				$this->cache->set( $keys_before_sql[ $folder_id ], $count, self::CACHE_TTL );
			}
		}

		return $result;
	}

	public function getRecursiveCount(int $folder_id, string $taxonomy): int {
		if ( $folder_id <= 0 || $folder_id === TrashFolder::id( $taxonomy ) || $this->repository->isUncategorizedFolder( $folder_id, $taxonomy ) ) {

			return $this->getCount( $folder_id, $taxonomy ) ?? 0;
		}

		$warm = $this->readWarmRecursiveCount( $folder_id );
		if ( null !== $warm ) {
			return $warm;
		}

		return $this->seedRecursiveCountIfCold( $folder_id, $taxonomy );
	}

	private function readWarmRecursiveCount(int $folder_id): ?int {
		$raw = get_term_meta( $folder_id, self::RECURSIVE_COUNT_META_KEY, true );
		if ( '' !== $raw && is_numeric( $raw ) ) {
			return max( 0, (int) $raw );
		}

		return null;
	}

	private function seedRecursiveCountIfCold(int $folder_id, string $taxonomy): int {
		$count = $this->calculateRecursiveCountFromScratch( $folder_id, $taxonomy );
		if ( null === $count ) {
			return 0;
		}

		$this->writeRecursiveCount( $folder_id, $count );

		return $count;
	}

	private function calculateRecursiveCountFromScratch(int $folder_id, string $taxonomy): ?int {
		$descendant_terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'child_of'   => $folder_id,
			'hide_empty' => false,
			'fields'     => 'ids',
		] );

		if ( is_wp_error( $descendant_terms ) ) {
			return null;
		}

		$subtree_ids = is_array( $descendant_terms ) ? array_map( 'intval', $descendant_terms ) : [];
		$subtree_ids[] = $folder_id;

		$counts = $this->calculator->batchCounts( $subtree_ids, $taxonomy );
		if ( null === $counts ) {
			return null;
		}

		return array_sum( $counts );
	}

	/**
	 * @param int $folder_id
	 */

	public function incrementRecursiveChain(int $folder_id, string $taxonomy, int $delta): void {
		if ( $folder_id <= 0 || 0 === $delta || $folder_id === TrashFolder::id( $taxonomy ) || $this->repository->isUncategorizedFolder( $folder_id, $taxonomy ) ) {
			return;
		}

		// get_ancestors() is the existing WP-native primitive already used elsewhere in
		// this codebase for the same taxonomy (FolderSwitchField) — not a new mechanism.
		$chain = array_merge( [ $folder_id ], array_map( 'intval', get_ancestors( $folder_id, $taxonomy, 'taxonomy' ) ) );

		foreach ( $chain as $id ) {
			$was_warm = null !== $this->readWarmRecursiveCount( $id );
			if ( ! $was_warm ) {
				// Cold: seeding via a live SQL COUNT already captures this mutation —
				// applying delta on top would double-count it. No-op past the seed.
				$this->seedRecursiveCountIfCold( $id, $taxonomy );
				continue;
			}

			$this->applyRecursiveCountDelta( $id, $delta );
		}
	}

	/**
	 * Atomic SQL increment/decrement (meta_value = meta_value + %d), not
	 * FolderRepository::setMeta()/update_term_meta() — those either clear the whole
	 * repository runtime cache (setMeta) or do a non-atomic SELECT-then-UPDATE round
	 * trip (update_term_meta), which loses updates under concurrent writers. Requires
	 * the row to already exist (guaranteed by getRecursiveCount() seeding above) —
	 * GREATEST(0, ...) clamps against the count ever going negative from a race between
	 * two concurrent decrements past a stale read.
	 */
	private function applyRecursiveCountDelta(int $folder_id, int $delta): void {
		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->termmeta}
				    SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)
				  WHERE term_id = %d AND meta_key = %s",
				$delta,
				$folder_id,
				$meta_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	private function writeRecursiveCount(int $folder_id, int $count): void {
		if ( null !== $this->readWarmRecursiveCount( $folder_id ) ) {
			// A row already exists — kept in sync by applyRecursiveCountDelta() on every
			// event since it was first seeded; must not be overwritten by a second, possibly
			// stale concurrent seed attempt racing the same cold read.
			return;
		}

		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
				 VALUES (%d, %s, %d)",
				$folder_id,
				$meta_key,
				$count
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	public function overwriteRecursiveCount(int $folder_id, int $count): void {
		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;
		$count    = max( 0, $count );

		if ( null === $this->readWarmRecursiveCount( $folder_id ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
					 VALUES (%d, %s, %d)",
					$folder_id,
					$meta_key,
					$count
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->termmeta} SET meta_value = %d WHERE term_id = %d AND meta_key = %s",
					$count,
					$folder_id,
					$meta_key
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	/**
	 * Returns the number of user folders (excluding special folders) for $taxonomy.
	 * Reads from the 'all' cache entry when warm — no extra SQL in that case.
	 * Falls back to a single COUNT(*) query when the cache is cold.
	 */
	public function countAll(string $taxonomy): int {
		$cache_key = $this->cache->versionedKey( 'folders_' . $taxonomy, 'all' );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			// Subtract the three protected special folders (All Files, Uncategorized, Trash).
			$special = array_filter( $cached, static fn (\Plathix\Core\FolderDTO $f): bool => $f->isProtected );
			return max( 0, count( $cached ) - count( $special ) );
		}

		return $this->calculator->countUserFolders( $taxonomy );
	}

	/** @return FolderDTO[] */
	public function getAllCached(string $taxonomy): array {
		$cache_key = $this->cache->versionedKey( 'folders_' . $taxonomy, 'all' );
		$cached = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$uncategorized_id = $this->repository->getUncategorizedTermId( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );
		$pt_obj   = get_post_type_object( Taxonomy::postTypeForTaxonomy( $taxonomy ) );
		$all_label = $pt_obj?->labels->all_items ?? __( 'All Files', 'plathix' );

		$raw_total_items   = $this->calculator->totalItemsCount( $taxonomy );
		$raw_uncategorized = $uncategorized_id > 0 ? $this->calculator->uncategorizedItemsCount( $taxonomy ) : null;

		$items = [
			new FolderDTO( self::ALL_FILES_ID, $all_label, FolderId::ROOT, -100, '', '', $raw_total_items ?? 0, $taxonomy, true ),
		];

		if ( $uncategorized_id > 0 ) {
			$items[] = new FolderDTO( $uncategorized_id, __( 'Uncategorized', 'plathix' ), FolderId::ROOT, -90, '', '', $raw_uncategorized ?? 0, $taxonomy, true );
		}

		$trashed_ids = array_flip( HiddenFolders::ids( $taxonomy ) );

		$post_type = Taxonomy::postTypeForTaxonomy( $taxonomy );
		if ( $post_type === 'attachment' && $trash_id > 0 ) {

			$items[] = new FolderDTO( $trash_id, __( 'Trash', 'plathix' ), FolderId::ROOT, -80, '', '', $this->calculator->trashItemsCount(), $taxonomy, true, false, count( $trashed_ids ) );
		}

		$all_terms = $this->repository->getAll( $taxonomy );

		// Collect custom term IDs (excluding the uncategorized special term and trashed folders).
		$custom_term_ids = [];
		foreach ( $all_terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id = (int) $term->term_id;
			if ( isset( $trashed_ids[ $term_id ] ) ) {
				continue;
			}
			if ( $uncategorized_id <= 0 || $term_id !== $uncategorized_id ) {
				$custom_term_ids[] = $term_id;
			}
		}

		$raw_batch_counts = $this->calculator->batchCounts( $custom_term_ids, $taxonomy );
		$batchCounts     = $raw_batch_counts ?? [];

		$sql_batch_failed = null === $raw_batch_counts
			|| null === $raw_total_items
			|| ( $uncategorized_id > 0 && null === $raw_uncategorized );

		update_termmeta_cache( $custom_term_ids );

		foreach ( $all_terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			if ( $uncategorized_id > 0 && (int) $term->term_id === $uncategorized_id ) {
				continue;
			}

			if ( $trash_id > 0 && (int) $term->term_id === $trash_id ) {
				continue;
			}

			if ( isset( $trashed_ids[ (int) $term->term_id ] ) ) {
				continue;
			}

			$items[] = new FolderDTO(
				(int) $term->term_id,
				(string) $term->name,
				(int) $term->parent,
				(int) $this->repository->getMeta( (int) $term->term_id, PLATHIX_TERM_POSITION ),
				(string) $this->repository->getMeta( (int) $term->term_id, PLATHIX_TERM_COLOR ),
				'',
				$batchCounts[ (int) $term->term_id ] ?? 0,
				$taxonomy,
				false,
				false,
				null,
				$this->getRecursiveCount( (int) $term->term_id, $taxonomy )
			);
		}

		usort(
			$items,
			static fn (FolderDTO $a, FolderDTO $b): int => $a->parentId <=> $b->parentId ?: $a->position <=> $b->position ?: strcasecmp( $a->name, $b->name )
		);

		$filtered = apply_filters( 'plathix/folder/list', $items, $taxonomy );
		$result = is_array( $filtered ) ? $filtered : $items;

		if ( ! $sql_batch_failed ) {
			$this->cache->set( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * @return FolderDTO[]
	 */

	public function getChildren(int $parent_id, string $taxonomy): array {
		$cache_key = $this->cache->versionedKey( 'folders_' . $taxonomy, 'children_' . $parent_id );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$uncategorized_id = $this->repository->getUncategorizedTermId( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );
		$hidden_ids       = array_flip( HiddenFolders::ids( $taxonomy ) );

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$child_ids = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( $id === $uncategorized_id || $id === $trash_id || isset( $hidden_ids[ $id ] ) ) {
				continue;
			}
			$child_ids[] = $id;
		}

		if ( empty( $child_ids ) ) {

			$this->cache->set( $cache_key, [], self::CACHE_TTL );
			return [];
		}

		$raw_batch_counts = $this->calculator->batchCounts( $child_ids, $taxonomy );
		$sql_batch_failed = null === $raw_batch_counts;
		$batchCounts     = $raw_batch_counts ?? [];

		// Determine which children have their own children — one SQL query via repository.
		$parents_with_children = $this->repository->getParentIdsThatHaveChildren( $child_ids, $taxonomy );
		$grandchild_parent_ids = array_fill_keys( $parents_with_children, true );

		update_termmeta_cache( $child_ids );

		$items = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( $id === $uncategorized_id || $id === $trash_id || isset( $hidden_ids[ $id ] ) ) {
				continue;
			}
			$items[] = new FolderDTO(
				$id,
				(string) $term->name,
				(int) $term->parent,
				(int) $this->repository->getMeta( $id, PLATHIX_TERM_POSITION ),
				(string) $this->repository->getMeta( $id, PLATHIX_TERM_COLOR ),
				'',
				$batchCounts[ $id ] ?? 0,
				$taxonomy,
				false,
				isset( $grandchild_parent_ids[ $id ] ),
				null,
				$this->getRecursiveCount( $id, $taxonomy )
			);
		}

		usort(
			$items,
			static fn (FolderDTO $a, FolderDTO $b): int => $a->position <=> $b->position ?: strcasecmp( $a->name, $b->name )
		);

		if ( ! $sql_batch_failed ) {
			$this->cache->set( $cache_key, $items, self::CACHE_TTL );
		}

		return $items;
	}

	/**
	 * @param int $post_id
	 * @param int $delta
	 */

	public function adjustForPost(int $post_id, int $delta): void {
		if ( $post_id <= 0 || 0 === $delta ) {
			return;
		}

		$post_type = (string) get_post_type( $post_id );
		if ( '' === $post_type || 'attachment' === $post_type ) {
			return;
		}

		$taxonomy = TaxonomyResolver::fromPostType( $post_type );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$folder_ids = null;
		if ( function_exists( 'get_object_term_cache' ) ) {
			$cached = get_object_term_cache( $post_id, $taxonomy );
			if ( is_array( $cached ) ) {
				$folder_ids = array_map( static fn (\WP_Term $t): int => (int) $t->term_id, $cached );
			}
		}
		if ( null === $folder_ids ) {
			$folder_ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $folder_ids ) ) {
				return;
			}
		}
		if ( [] === $folder_ids ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->incrementRecursiveChain( (int) $folder_id, $taxonomy, $delta );
		}

		$this->invalidate( $taxonomy );
	}


	public function invalidate(string $taxonomy): void {
		if ( isset( $this->bulk_invalidations[ $taxonomy ] ) ) {
			$this->bulk_invalidations[ $taxonomy ] = true;
			return;
		}

		$this->cache->deleteGroup( 'folders_' . $taxonomy );
		FolderRepository::clearRuntimeCache();
	}

	public function invalidateAllTaxonomies(): void {
		$taxonomies = array_unique( array_merge( [ PLATHIX_TAXONOMY ], Taxonomy::getEnabledTaxonomies() ) );
		foreach ( $taxonomies as $taxonomy ) {
			$this->invalidate( (string) $taxonomy );
		}
	}

	public function beginBulkWrite(string $taxonomy): void {
		$this->bulk_invalidations[ $taxonomy ] = false;
	}

	public function endBulkWrite(string $taxonomy): void {
		if ( ! array_key_exists( $taxonomy, $this->bulk_invalidations ) ) {
			return;
		}

		unset( $this->bulk_invalidations[ $taxonomy ] );
		$this->cache->deleteGroup( 'folders_' . $taxonomy );
		FolderRepository::clearRuntimeCache();
	}
}
