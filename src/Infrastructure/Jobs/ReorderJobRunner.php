<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Infrastructure\JobLockService;

/**
 * Handles the plathix_job_reorder Action Scheduler job.
 */
final class ReorderJobRunner
{
	private JobLockService $lock_service;

	public function __construct(JobLockService $lock_service) {
		$this->lock_service = $lock_service;
	}

	/** @param array<string, mixed> $args */
	public function run(array $args, callable $run_in_blog_context, callable $release_fingerprint): void {
		$taxonomy  = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );
		$parent_id = (int) ( $args['parent_id'] ?? 0 );
		$blog_id   = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		try {
			$run_in_blog_context(
				$blog_id,
				function () use ($taxonomy, $parent_id): void {
					if ( '' === $taxonomy ) {
						return;
					}

					// Acquire the same per-branch lock used by set_order() so a concurrent
					// DnD save cannot be overwritten by a stale reorder job (spec §set_order-vs-JOB_REORDER).
					$lock_name = $this->lock_service->order_lock_name( $taxonomy, $parent_id );

					$lock = $this->lock_service->acquire_order( $lock_name );

					if ( $lock['mode'] === 'none' ) {
						return; // Lock held by set_order(); bail to preserve DnD result.
					}

					try {
						$terms = get_terms(
							[
								'taxonomy'   => $taxonomy,
								'parent'     => $parent_id,
								'hide_empty' => false,
								'fields'     => 'ids',
								'orderby'    => 'meta_value_num',
								'meta_key'   => PLATHIX_TERM_POSITION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key via orderby is the only WP-native way to sort terms by a numeric position field for drag-n-drop reorder.
							]
						);

						if ( is_wp_error( $terms ) || empty( $terms ) ) {
							return;
						}

						$position = 1000;
						foreach ( $terms as $term_id ) {
							update_term_meta( (int) $term_id, PLATHIX_TERM_POSITION, $position );
							$position += 1000;
						}
					} finally {
						$this->lock_service->release_order( $lock_name, $lock );
					}
				}
			);
		} finally {
			$release_fingerprint( \Plathix\Infrastructure\JobDispatcher::JOB_REORDER, $args );
		}
	}
}
