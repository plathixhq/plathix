<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Infrastructure\Cache;
use Plathix\User\AccessLevel;

final class ImportAjaxHandler
{
	public function __construct(
		private readonly ?JobDispatcher $jobs = null,
		private readonly ?RateLimiter $rateLimiter = null,
	) {
	}

	public function register(): void
	{
		add_action( 'wp_ajax_plathix_import', [ $this, 'handle' ] );
		add_action( 'wp_ajax_plathix_import_status', [ $this, 'handleStatus' ] );
	}

	public function handle(): void
	{
		$this->guard();

		$adapter = sanitize_key( (string) wp_unslash( $_POST['adapter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verifyOrDie()
		$restart = 'true' === sanitize_key( (string) wp_unslash( $_POST['restart'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verifyOrDie()
		$post_type = $this->requestPostType();

		$available = ( new ImportManager() )->available();
		if ( $adapter === '' || ! array_key_exists( $adapter, $available ) || ! $available[ $adapter ] ) {
			wp_send_json_error( [ 'code' => 'invalid_import_adapter', 'message' => __( 'Import adapter is not available.', 'plathix' ) ], 400 );
		}

		if ( $restart ) {

			if ( 'locked' === ( new ImportManager() )->rollbackPartial( $adapter ) ) {
				wp_send_json_error(
					[
						'code'    => 'import_running',
						'message' => __( 'Import is already running for this adapter — cannot restart yet.', 'plathix' ),
					],
					409
				);
			}
		}
		$dispatch_reason = $this->rateLimiter()->canDispatchHeavyJob( JobDispatcher::JOB_IMPORT, [ 'adapter' => $adapter, 'post_type' => $post_type ], get_current_user_id() );

		if ( $dispatch_reason === 'per_user' ) {
			wp_send_json_error( [ 'code' => 'job_already_queued', 'message' => __( 'Import job already queued for this user.', 'plathix' ) ], 429 );
		}

		if ( $dispatch_reason === 'server_cap' ) {
			status_header( 429 );
			header( 'Retry-After: 30' );
			wp_send_json_error( [ 'code' => 'server_busy', 'message' => __( 'Import queue is busy.', 'plathix' ) ], 429 );
		}

		$result = ( new ImportManager() )->startImport( $adapter, $post_type, get_current_user_id() );

		wp_send_json_success(
			[
				'queued'   => $result->isQueued(),
				'jobId'    => $result->jobId,
				'status'   => $result->status,
				'adapter'  => $result->adapter,
				'postType' => $result->postType,
			]
		);
	}

	public function handleStatus(): void
	{
		$this->guard();

		$job_id = absint( wp_unslash( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verifyOrDie()

		if ( $job_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job id.', 'plathix' ) ], 400 );
		}

		wp_send_json_success( $this->jobs()->getStatus( $job_id ) );
	}

	private function guard(): void
	{
		AjaxGuard::require( AccessLevel::Full, 'manage_options', $this->requestPostType() );
	}

	/**
	 * @return string
	 */

	private function requestPostType(): string
	{
		return sanitize_key( (string) wp_unslash( $_POST['post_type'] ?? $_REQUEST['post_type'] ?? 'attachment' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read while evaluating the AjaxGuard::require() argument, i.e. BEFORE the nonce check inside it (PHP evaluates arguments first). Safe because the value is only sanitize_key()'d and used to resolve which capability to demand; it causes no side effect and no data access before AjaxGuard::require() verifies nonce+capability. Do not add side effects here.
	}

	private function jobs(): JobDispatcher
	{
		return $this->jobs ?? new JobDispatcher();
	}

	private function rateLimiter(): RateLimiter
	{
		return $this->rateLimiter ?? new RateLimiter( Cache::make() );
	}
}
