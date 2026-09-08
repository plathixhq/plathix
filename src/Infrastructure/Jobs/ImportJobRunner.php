<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Infrastructure\Logger;

/**
 * Handles the plathix_job_import Action Scheduler job.
 */
final class ImportJobRunner
{
	/** @param array<string, mixed> $args */
	public function run(array $args, callable $runInBlogContext, callable $releaseFingerprint): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		try {
			$runInBlogContext(
				$blog_id,
				static function () use ($args): void {

					$user_id = (int) ( $args['user_id'] ?? 0 );
					$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
					if ( ! $user instanceof \WP_User ) {

						Logger::error( 'job_import_actor_missing', [
							'user_id' => $user_id,
							'adapter' => (string) ( $args['adapter'] ?? '' ),
							'summary' => 'Import aborted: initiating user no longer exists',
						] );
						throw new \RuntimeException( 'Import aborted: initiating user no longer exists.' );
					}
					wp_set_current_user( $user_id );

					do_action( 'plathix/import/job', $args );
				}
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'job_import_failed', [ 'adapter' => (string) ( $args['adapter'] ?? '' ) ], $e );
			throw $e;
		} finally {
			$releaseFingerprint( \Plathix\Infrastructure\JobDispatcher::JOB_IMPORT, $args );
		}
	}
}
