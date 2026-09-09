<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Core\FolderCountCalculator;
use Plathix\Core\FolderCountLifecycle;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;

/**
 * Handles the plathix_job_orphan_cleanup Action Scheduler job.
 */
final class OrphanCleanupJobRunner
{
	private JobLockService $lock_service;

	public function __construct(JobLockService $lock_service) {
		$this->lock_service = $lock_service;
	}

	/** @param array<string, mixed> $args */
	public function run(array $args, callable $runInBlogContext): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$runInBlogContext(
			$blog_id,
			function (): void {
				$saved_taxonomies = (array) get_option( 'plathix_taxonomies', [] );
				$live_taxonomies  = array_values(
					array_filter(
						get_taxonomies(),
						static fn(string $taxonomy): bool => TaxonomyResolver::isPlathixTaxonomy( $taxonomy )
					)
				);
				$taxonomies = array_unique( array_merge( $saved_taxonomies, $live_taxonomies ) );

				foreach ( $taxonomies as $taxonomy ) {
					$taxonomy = sanitize_key( (string) $taxonomy );
					if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
						continue;
					}

					$terms = get_terms(
						[
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'ids',
						]
					);

					if ( is_wp_error( $terms ) ) {
						continue;
					}

					foreach ( array_chunk( $terms, FolderCountReconcileJobRunner::CHUNK_SIZE ) as $chunk ) {
						$candidates = ( new FolderCountCalculator() )->findOrphanObjectIds( array_map( 'intval', $chunk ), $taxonomy );

						if ( null === $candidates ) {
							continue;
						}

						foreach ( $candidates as $term_id => $object_ids ) {
							foreach ( $object_ids as $object_id ) {
								$post = get_post( $object_id );

								if ( ! $post ) {
									FolderCountLifecycle::suppress(
										static fn () => wp_remove_object_terms( $object_id, $term_id, $taxonomy )
									);
								}
							}
						}
					}

					$missing_position_terms = get_terms(
						[
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'all',
							'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query with NOT EXISTS is the only WP-native way to find terms missing a specific meta key (position backfill), not a hot request path.
								[
									'key'     => PLATHIX_TERM_POSITION,
									'compare' => 'NOT EXISTS',
								],
							],
						]
					);

					if ( is_wp_error( $missing_position_terms ) ) {
						continue;
					}

					foreach ( $this->buildMissingPositionBackfill( (array) $missing_position_terms, $taxonomy ) as $term_id => $position ) {
						$term_id      = (int) $term_id;
						$old_position = get_term_meta( $term_id, PLATHIX_TERM_POSITION, true );
						$written      = update_term_meta( $term_id, PLATHIX_TERM_POSITION, $position );

						if ( ! $written && (int) $old_position !== $position ) {
							Logger::error( 'orphan_cleanup_position_backfill_write_failed', [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
						}
					}
				}

			}
		);
	}

	/**
	 * @param array<int,\WP_Term> $terms
	 * @return array<int,int>
	 */
	private function buildMissingPositionBackfill(array $terms, string $taxonomy): array {
		if ( $terms === [] ) {
			return [];
		}

		$grouped = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$parent_id = (int) $term->parent;
			if ( ! isset( $grouped[ $parent_id ] ) ) {
				$grouped[ $parent_id ] = [];
			}

			$grouped[ $parent_id ][] = $term;
		}

		$result = [];

		foreach ( $grouped as $parent_id => $children ) {

			$lock_name = $this->lock_service->orderLockName( $taxonomy, (int) $parent_id );
			$lock      = $this->lock_service->acquireOrder( $lock_name );

			if ( $lock['mode'] === 'none' ) {
				continue;
			}

			try {
				$max_position = 0;
				$raw_sibling_ids = get_terms(
					[
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'parent'     => (int) $parent_id,
						'fields'     => 'ids',
					]
				);

				if ( is_wp_error( $raw_sibling_ids ) ) {
					Logger::warning( 'orphan_cleanup_job_runner_sibling_terms_failed', [ 'taxonomy' => $taxonomy, 'parent_id' => (int) $parent_id ] );
				}
				$sibling_ids = is_wp_error( $raw_sibling_ids ) || ! is_array( $raw_sibling_ids ) ? [] : $raw_sibling_ids;

				// Batch-prime term meta cache (fields=ids skips automatic priming).
				if ( ! empty( $sibling_ids ) ) {
					update_termmeta_cache( $sibling_ids );
				}

				foreach ( $sibling_ids as $sibling_id ) {
					$position = (int) get_term_meta( (int) $sibling_id, PLATHIX_TERM_POSITION, true );
					if ( $position > $max_position ) {
						$max_position = $position;
					}
				}

				usort(
					$children,
					static fn(\WP_Term $left, \WP_Term $right): int => strcasecmp( $left->name, $right->name )
				);

				foreach ( $children as $term ) {
					$max_position = $max_position > 0 ? $max_position + 1000 : 1000;
					$result[ (int) $term->term_id ] = $max_position;
				}
			} finally {
				$this->lock_service->releaseOrder( $lock_name, $lock );
			}
		}

		return $result;
	}
}
