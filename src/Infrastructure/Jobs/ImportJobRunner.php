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
	public function run(array $args, callable $run_in_blog_context, callable $release_fingerprint): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		try {
			$run_in_blog_context(
				$blog_id,
				static function () use ($args): void {
					// Import-актор ([internal]): раскладка по папкам идёт
					// через FolderAssignmentService с per-item current_user_can. Под Action
					// Scheduler текущего юзера нет (get_current_user_id()===0) → проверка бы
					// завалила ВСЕ вложения. Восстанавливаем актора, поставившего импорт.
					// Резолв ЗДЕСЬ (внутри blog-context, после switch_to_blog) — иначе на
					// multisite юзер резолвится не в том блоге.
					$user_id = (int) ( $args['user_id'] ?? 0 );
					$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
					if ( ! $user instanceof \WP_User ) {
						// Юзер удалён/невалиден: НЕ раскладывать от чужого/системного имени и
						// НЕ рапортовать ложный успех — прервать с записью в лог.
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
			$release_fingerprint( \Plathix\Infrastructure\JobDispatcher::JOB_IMPORT, $args );
		}
	}
}
