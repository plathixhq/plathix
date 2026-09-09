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
			Logger::error( 'getStatus: fetch_action failed', [ 'action_id' => $action_id ], $e );
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		if ( $action instanceof \ActionScheduler_NullAction ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		$payload = $action->get_args()[0] ?? [];

		if ( (int) ( $payload['blog_id'] ?? 0 ) !== get_current_blog_id() ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		if ( ! IdentityKeyResolver::matchesOwner( $payload ) ) {
			return [ 'status' => 'not_found', 'result' => null, 'attempts' => 0 ];
		}

		$status     = $store->get_status( $action_id );
		$jobResult = get_option( Keys::jobResult( $action_id ) );
		$result     = null;

		// Inline generation: result was stored synchronously before AS worker ran.
		// AS still shows 'pending', but the file is already ready.
		if ( $status !== 'complete' && is_array( $jobResult ) ) {
			$fp = (string) ( $jobResult['file_path'] ?? '' );
			if ( $fp !== '' && file_exists( $fp ) ) {
				$status = 'complete';
			}
		}

		if ( 'complete' === $status && is_array( $jobResult ) ) {
			$file_path = (string) ( $jobResult['file_path'] ?? '' );

			if ( '' !== $file_path ) {
				if ( file_exists( $file_path ) ) {
					set_transient(
						Keys::download( $action_id ),
						array_filter(
							[
								'file_path'           => $file_path,
								'folder_id'           => $jobResult['folder_id'] ?? 0,
								'user_id'             => $jobResult['user_id'] ?? 0,
								'blog_id'             => $jobResult['blog_id'] ?? 0,
								'post_type'           => $jobResult['post_type'] ?? '',

								'created_by_token_id' => $jobResult['created_by_token_id'] ?? null,
							],
							static fn ($value): bool => $value !== null
						),
							5 * MINUTE_IN_SECONDS
					);

					$result = array_filter(
						[
							'ready'         => true,
							'expires'       => time() + 5 * MINUTE_IN_SECONDS,
							'partial'       => ( $jobResult['partial'] ?? false ) === true ? true : null,
							'skipped_count' => ( $jobResult['partial'] ?? false ) === true ? (int) ( $jobResult['skipped_count'] ?? 0 ) : null,
						],
						static fn ($value): bool => $value !== null
					);
				} else {
					$result = [ 'expired' => true ];
				}
			} else {

				$result = array_diff_key(
					$jobResult,
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
