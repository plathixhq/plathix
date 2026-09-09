<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;

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
	public function run(array $args, callable $runInBlogContext, callable $releaseFingerprint): void {
		$taxonomy  = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );
		$parent_id = (int) ( $args['parent_id'] ?? 0 );
		$blog_id   = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		try {
			$runInBlogContext(
				$blog_id,
				function () use ($taxonomy, $parent_id): void {
					if ( '' === $taxonomy ) {
						return;
					}

					$lock_name = $this->lock_service->orderLockName( $taxonomy, $parent_id );

					$lock = $this->lock_service->acquireOrder( $lock_name );

					if ( $lock['mode'] === 'none' ) {
						return; // Lock held by setOrder(); bail to preserve DnD result.
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
							$term_id      = (int) $term_id;
							$old_position = get_term_meta( $term_id, PLATHIX_TERM_POSITION, true );
							$written      = update_term_meta( $term_id, PLATHIX_TERM_POSITION, $position );

							if ( ! $written && (int) $old_position !== $position ) {
								Logger::error( 'reorder_job_position_meta_write_failed', [ 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'parent_id' => $parent_id ] );
							}

							$position += 1000;
						}
					} finally {
						$this->lock_service->releaseOrder( $lock_name, $lock );
					}
				}
			);
		} finally {
			$releaseFingerprint( \Plathix\Infrastructure\JobDispatcher::JOB_REORDER, $args );
		}
	}
}
