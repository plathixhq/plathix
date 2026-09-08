<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Logger;

final class FolderCountLifecycle
{

	private static bool $suppressed = false;

	private static array $tt_to_term = [];

	private static array $pending_delete_deltas = [];

	public function __construct(private readonly FolderCountService $countService)
	{
	}

	public function register(): void
	{

		// ($object_id, $tt_id, $taxonomy), deleted_term_relationships ($object_id, $tt_ids, $taxonomy).
		add_action( 'added_term_relationship', [ $this, 'onAddedTermRelationship' ], 10, 3 );
		add_action( 'deleted_term_relationships', [ $this, 'onDeletedTermRelationships' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'onTrashedPost' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'onUntrashedPost' ], 10, 1 );

		add_action( 'delete_attachment', [ $this, 'onDeleteAttachment' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'onBeforeDeletePost' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'onDeletedPost' ], 10, 1 );

		add_action( 'added_post_meta', [ $this, 'onAddedPostMeta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'onDeletedPostMeta' ], 10, 4 );
	}

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
	 * @param int|string $object_id
	 * @param int|string $tt_id
	 * @param string     $taxonomy
	 */

	public function onAddedTermRelationship($object_id, $tt_id, $taxonomy): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$taxonomy = (string) $taxonomy;
		if ( ! TaxonomyResolver::isPlathixTaxonomy( $taxonomy ) ) {
			return;
		}

		$object_id = (int) $object_id;
		if ( get_post_type( $object_id ) !== Taxonomy::postTypeForTaxonomy( $taxonomy ) ) {
			return;
		}
		if ( ! $this->isCountable( $object_id ) ) {
			return;
		}

		[ $term_id ] = $this->ttIdsToTermIds( [ (int) $tt_id ], $taxonomy );
		$this->countService->incrementRecursiveChain( $term_id, $taxonomy, +1 );
	}

	/**
	 * @param int|string        $object_id
	 * @param array<int|string> $tt_ids
	 * @param string            $taxonomy
	 */

	public function onDeletedTermRelationships($object_id, $tt_ids, $taxonomy): void
	{
		if ( self::$suppressed ) {
			return;
		}
		$taxonomy = (string) $taxonomy;
		if ( ! TaxonomyResolver::isPlathixTaxonomy( $taxonomy ) ) {
			return;
		}

		$object_id = (int) $object_id;
		if ( null === get_post( $object_id ) ) {

			return;
		}
		if ( get_post_type( $object_id ) !== Taxonomy::postTypeForTaxonomy( $taxonomy ) ) {
			return;
		}
		if ( ! $this->isCountable( $object_id ) ) {
			return;
		}

		$tt_ids = array_map( 'intval', (array) $tt_ids );
		if ( $tt_ids === [] ) {
			return;
		}
		foreach ( $this->ttIdsToTermIds( $tt_ids, $taxonomy ) as $term_id ) {
			$this->countService->incrementRecursiveChain( $term_id, $taxonomy, -1 );
		}
	}

	/**
	 * @param int|string $post_id
	 */

	public function onTrashedPost($post_id): void
	{
		$this->applyTrashTransitionDelta( (int) $post_id, -1 );
	}

	/**
	 * @param int|string $post_id
	 */

	public function onUntrashedPost($post_id): void
	{
		$this->applyTrashTransitionDelta( (int) $post_id, +1 );
	}

	private function applyTrashTransitionDelta(int $post_id, int $delta): void
	{
		if ( self::$suppressed ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		if ( ! AttachmentVisibility::isVisibleByMeta( $post_id ) ) {

			return;
		}

		$taxonomy   = PLATHIX_TAXONOMY;
		$folder_ids = $this->readFolderIds( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->countService->incrementRecursiveChain( $folder_id, $taxonomy, $delta );
		}
		$this->countService->invalidate( $taxonomy );
	}

	/**
	 * @param int|string $post_id
	 */

	public function onDeleteAttachment($post_id): void
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
		if ( ! AttachmentVisibility::isVisibleByMeta( $post_id ) ) {
			return;
		}

		$folder_ids = $this->readFolderIds( $post_id, PLATHIX_TAXONOMY );
		if ( $folder_ids === [] ) {
			return;
		}
		self::$pending_delete_deltas[ $post_id ] = [
			'terms'    => $folder_ids,
			'taxonomy' => PLATHIX_TAXONOMY,
		];
	}

	/**
	 * @param int|string $post_id
	 * @param mixed      $post
	 */

	public function onBeforeDeletePost($post_id, $post = null): void
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

		$status = $post instanceof \WP_Post ? (string) $post->post_status : (string) get_post_status( $post_id );
		if ( 'trash' === $status ) {
			return;
		}

		$folder_ids = $this->readFolderIds( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}
		self::$pending_delete_deltas[ $post_id ] = [
			'terms'    => $folder_ids,
			'taxonomy' => $taxonomy,
		];
	}

	/**
	 * @param int|string $post_id
	 */

	public function onDeletedPost($post_id): void
	{
		$post_id = (int) $post_id;
		$pending = self::$pending_delete_deltas[ $post_id ] ?? null;
		if ( null === $pending ) {
			return;
		}
		unset( self::$pending_delete_deltas[ $post_id ] );

		if ( self::$suppressed ) {

			return;
		}

		foreach ( $pending['terms'] as $folder_id ) {
			$this->countService->incrementRecursiveChain( $folder_id, $pending['taxonomy'], -1 );
		}
		$this->countService->invalidate( $pending['taxonomy'] );
	}

	/**
	 * @param int|string        $meta_id
	 * @param int|string        $object_id
	 * @param string            $meta_key
	 * @param mixed             $meta_value
	 */

	public function onAddedPostMeta($meta_id, $object_id, $meta_key, $meta_value = null): void
	{
		$this->applyVisibilityTransitionDelta( (int) $object_id, (string) $meta_key, -1 );
	}

	/**
	 * @param int[]|int|string  $meta_ids
	 * @param int|string        $object_id
	 * @param string            $meta_key
	 * @param mixed             $meta_value
	 */

	public function onDeletedPostMeta($meta_ids, $object_id, $meta_key, $meta_value = null): void
	{
		$this->applyVisibilityTransitionDelta( (int) $object_id, (string) $meta_key, +1 );
	}

	private function applyVisibilityTransitionDelta(int $post_id, string $meta_key, int $delta): void
	{
		if ( self::$suppressed ) {
			return;
		}
		if ( ! in_array( $meta_key, AttachmentVisibility::excludeMetaKeys(), true ) ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		if ( ! AttachmentVisibility::isVisibleStatus( (string) get_post_status( $post_id ) ) ) {

			return;
		}
		if ( ! AttachmentVisibility::isVisibleByMetaExcept( $post_id, $meta_key ) ) {

			return;
		}

		$taxonomy   = PLATHIX_TAXONOMY;
		$folder_ids = $this->readFolderIds( $post_id, $taxonomy );
		if ( $folder_ids === [] ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->countService->incrementRecursiveChain( $folder_id, $taxonomy, $delta );
		}
		$this->countService->invalidate( $taxonomy );
	}

	/**
	 * @return array<int, int>
	 */

	private function readFolderIds(int $post_id, string $taxonomy): array
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

	private function isCountable(int $post_id): bool
	{
		return AttachmentVisibility::isVisibleStatus( (string) get_post_status( $post_id ) )
			&& AttachmentVisibility::isVisibleByMeta( $post_id );
	}

	/**
	 * @param array<int, int> $tt_ids
	 * @return array<int, int>
	 */

	private function ttIdsToTermIds(array $tt_ids, string $taxonomy): array
	{
		$missing = array_values( array_filter( $tt_ids, static fn (int $tt): bool => ! isset( self::$tt_to_term[ $tt ] ) ) );

		if ( $missing !== [] ) {
			global $wpdb;
			$id_list = implode( ',', array_map( 'intval', $missing ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $id_list is intval'd; core has no batch tt_id->term_id API, mirror of FolderCountCalculator's own IN-list form
			$rows = $wpdb->get_results( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$id_list})" );

			if ( null === $rows ) {
				Logger::warning( 'folder_count_lifecycle_tt_to_term_lookup_sql_failed', [ 'missing_count' => count( $missing ) ] );
			}

			foreach ( SqlSafeCast::nullSafeSqlRows( $rows ) ?? [] as $row ) {
				self::$tt_to_term[ (int) $row->term_taxonomy_id ] = (int) $row->term_id;
			}

			foreach ( $missing as $tt ) {
				self::$tt_to_term[ $tt ] ??= $tt;
			}
		}

		return array_values( array_map( static fn (int $tt): int => self::$tt_to_term[ $tt ], $tt_ids ) );
	}

	public static function resetRuntimeState(): void
	{
		self::$tt_to_term            = [];
		self::$suppressed            = false;
		self::$pending_delete_deltas = [];
	}
}
