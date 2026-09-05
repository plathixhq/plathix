<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Reads job status and result from Action Scheduler + option storage.
 */
final class JobStatusRepository
{
	/**
	 * @return array{status: string, result: mixed, attempts: int}
	 */
	public function get(int $action_id): array {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_NullAction' ) ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		$store = \ActionScheduler::store();

		try {
			$action = $store->fetch_action( $action_id );
		} catch ( \Throwable $e ) {
			Logger::error( 'get_status: fetch_action failed', [ 'action_id' => $action_id ], $e );
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		if ( $action instanceof \ActionScheduler_NullAction ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		$payload = $action->get_args()[0] ?? [];

		if ( (int) ( $payload['blog_id'] ?? 0 ) !== get_current_blog_id() ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		// [internal]: сравнение через identity-key, не raw user_id — при активном service-токене
		// job-принадлежность резолвится по token_id, не по impersonated admin ID, иначе токен и
		// реальный admin делят один job-ownership identity (false 'not_found' cross-blocking).
		//
		// [internal] ([internal]): payload-сторона резолвится через фильтр
		// `plathix/infrastructure/resolve_owner_identity` — если payload несёт
		// `created_by_token_id`, identity строится из НЕГО (кто реально создал job), не из
		// токена, активного на ТЕКУЩЕМ read-запросе.
		//
		// [internal] ([internal]): раньше здесь была inline-копия этой
		// проверки с `isset($payload['user_id'])`-guard (пропускала проверку целиком при
		// отсутствии user_id) — разошлась с двумя PRO-копиями (fail-closed без guard'а).
		// `IdentityKeyResolver::matchesOwner()` — единственная реализация, fail-closed по
		// умолчанию (отсутствие user_id больше не пропускает проверку).
		if ( ! IdentityKeyResolver::matchesOwner( $payload ) ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		$status     = $store->get_status( $action_id );
		$job_result = get_option( Keys::job_result( $action_id ) );
		$result     = null;

		// Inline generation: result was stored synchronously before AS worker ran.
		// AS still shows 'pending', but the file is already ready.
		if ( $status !== 'complete' && is_array( $job_result ) ) {
			$fp = (string) ( $job_result['file_path'] ?? '' );
			if ( $fp !== '' && file_exists( $fp ) ) {
				$status = 'complete';
			}
		}

		if ( 'complete' === $status && is_array( $job_result ) ) {
			$file_path = (string) ( $job_result['file_path'] ?? '' );

			if ( '' !== $file_path ) {
				if ( file_exists( $file_path ) ) {
					set_transient(
						Keys::download( $action_id ),
						array_filter(
							[
								'file_path'           => $file_path,
								'folder_id'           => $job_result['folder_id'] ?? 0,
								'user_id'             => $job_result['user_id'] ?? 0,
								'blog_id'             => $job_result['blog_id'] ?? 0,
								'post_type'           => $job_result['post_type'] ?? '',
								// [internal] ([internal]): переносим created_by_token_id из
								// job_result в download-transient — DownloadController::
								// stream_job_zip() (PRO) читает owner identity отсюда, не из
								// job_result напрямую.
								'created_by_token_id' => $job_result['created_by_token_id'] ?? null,
							],
							static fn ($value): bool => $value !== null
						),
							5 * MINUTE_IN_SECONDS
					);
					// [internal]: partial/skipped_count — addable-alongside, не входит в
					// download-transient (DownloadController не нуждается в этом для отдачи
					// файла), но обязан дойти до клиента через REST-статус, иначе честный
					// backend-подсчёт провалов копирования (ZipJobRunner::generate()) остаётся
					// невидимым — issue's Expected-2 требует именно "явное предупреждение".
					$result = array_filter(
						[
							'ready'         => true,
							'expires'       => time() + 5 * MINUTE_IN_SECONDS,
							'partial'       => ( $job_result['partial'] ?? false ) === true ? true : null,
							'skipped_count' => ( $job_result['partial'] ?? false ) === true ? (int) ( $job_result['skipped_count'] ?? 0 ) : null,
						],
						static fn ($value): bool => $value !== null
					);
				} else {
					$result = [ 'expired' => true ];
				}
			} else {
				// [internal]: _created_at — служебная TTL-метка (CleanupJobRunner::
				// purge_stale_job_results()), не часть публичного result-контракта.
				$result = array_diff_key(
					$job_result,
					[
						'user_id'     => true,
						'blog_id'     => true,
						'_created_at' => true,
					]
				);
			}
		}

		return [
			'status'   => $status,
			'result'   => $result,
			// @phpstan-ignore method.nonObject (Action Scheduler stub types $action as class-string|object; method_exists() guard above is the real runtime check)
			'attempts' => method_exists( $action, 'get_attempts' ) ? (int) $action->get_attempts() : 0,
		];
	}
}
