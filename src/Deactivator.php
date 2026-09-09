<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Infrastructure\JobDispatcher;

class Deactivator
{

	public static function run(bool $network_wide = false): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::runForSite( (int) $site_id );
				restore_current_blog();
			}

			return;
		}

		self::runForSite( get_current_blog_id() );
	}

	private static function runForSite(int $blog_id): void {

		$group = JobDispatcher::groupForBlog( $blog_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {

			$recurring_args = JobDispatcher::recurringUnscheduleArgs( $blog_id );
			as_unschedule_all_actions( JobDispatcher::JOB_CLEANUP_TEMP, $recurring_args, $group );
			as_unschedule_all_actions( JobDispatcher::JOB_ORPHAN_CLEANUP, $recurring_args, $group );

			as_unschedule_all_actions( JobDispatcher::JOB_IMPORT, [], $group );
			as_unschedule_all_actions( JobDispatcher::JOB_REORDER, [], $group );
			as_unschedule_all_actions( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, $recurring_args, $group );

			as_unschedule_all_actions( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, $recurring_args, $group );
		}

		do_action( 'plathix/jobs/unschedule', $blog_id );

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup write (DELETE transient job-result options) at deactivation; runs once, caching N/A for writes
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'plathix_job_result_' ) . '%'
			)
		);
	}
}
