<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

use Plathix\Loader;

final class JobDispatcher
{

	public const JOB_ZIP_GENERATE   = 'plathix_job_zip_generate';
	public const JOB_IMPORT         = 'plathix_job_import';
	public const JOB_CLEANUP_TEMP   = 'plathix_job_cleanup_temp';
	public const JOB_ORPHAN_CLEANUP = 'plathix_job_orphan_cleanup';
	public const JOB_REORDER        = 'plathix_job_reorder';
	public const JOB_IMPORT_CHECKPOINT_CLEANUP = 'plathix_job_import_checkpoint_cleanup';

	public const JOB_FOLDER_COUNT_RECONCILE = 'plathix_job_folder_count_reconcile';
	public const JOB_FOLDER_COUNT_RECONCILE_INTERVAL = DAY_IN_SECONDS;

	public const JOB_CLEANUP_TEMP_INTERVAL = HOUR_IN_SECONDS * 2;

	public const MAX_ATTEMPTS       = 5;

	public const RESULT_EXECUTED     = 'executed';
	public const RESULT_ALREADY_DONE = 'already_done';
	public const RESULT_BUSY         = 'busy';

	private JobLockService $lock_service;
	private JobStatusRepository $status_repository;
	private Jobs\ImportJobRunner $import_runner;
	private Jobs\ReorderJobRunner $reorder_runner;
	private Jobs\OrphanCleanupJobRunner $orphan_runner;
	private Jobs\CleanupJobRunner $cleanup_runner;
	private Jobs\ImportCheckpointCleanupJobRunner $import_checkpoint_cleanup_runner;
	private Jobs\FolderCountReconcileJobRunner $folder_count_reconcile_runner;

	public function __construct() {
		$this->lock_service     = new JobLockService();
		$this->status_repository = new JobStatusRepository();
		$this->import_runner    = new Jobs\ImportJobRunner();
		$this->reorder_runner   = new Jobs\ReorderJobRunner( $this->lock_service );
		$this->orphan_runner    = new Jobs\OrphanCleanupJobRunner();
		$this->cleanup_runner   = new Jobs\CleanupJobRunner();
		$this->import_checkpoint_cleanup_runner = new Jobs\ImportCheckpointCleanupJobRunner();
		$this->folder_count_reconcile_runner    = new Jobs\FolderCountReconcileJobRunner( $this->lock_service );
	}

	public function registerHandlers(?Loader $loader = null): void {
		if ( $loader ) {
			$loader->addAction( self::JOB_IMPORT, $this, 'handleImport' );
			$loader->addAction( self::JOB_REORDER, $this, 'handleReorder' );
			$loader->addAction( self::JOB_CLEANUP_TEMP, $this, 'handleCleanup' );
			$loader->addAction( self::JOB_ORPHAN_CLEANUP, $this, 'handleOrphanCleanup' );
			$loader->addAction( self::JOB_IMPORT_CHECKPOINT_CLEANUP, $this, 'handleImportCheckpointCleanup' );
			$loader->addAction( self::JOB_FOLDER_COUNT_RECONCILE, $this, 'handleFolderCountReconcile' );
			$this->announceExtensibleHandlers();
			return;
		}

		add_action( self::JOB_IMPORT, [ $this, 'handleImport' ] );
		add_action( self::JOB_REORDER, [ $this, 'handleReorder' ] );
		add_action( self::JOB_CLEANUP_TEMP, [ $this, 'handleCleanup' ] );
		add_action( self::JOB_ORPHAN_CLEANUP, [ $this, 'handleOrphanCleanup' ] );
		add_action( self::JOB_IMPORT_CHECKPOINT_CLEANUP, [ $this, 'handleImportCheckpointCleanup' ] );
		add_action( self::JOB_FOLDER_COUNT_RECONCILE, [ $this, 'handleFolderCountReconcile' ] );
		$this->announceExtensibleHandlers();
	}

	private function announceExtensibleHandlers(): void {
		do_action( 'plathix/jobs/register_handlers', $this );
	}

	public function getTempDir(): string {
		return ( new TempDirectory() )->path();
	}

	/**
	 * @param array<string, mixed> $args
	 */

	public function dispatch(string $job, array $args = [], int $delay = 0): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::warning( 'job_dispatch_skipped_no_action_scheduler', [ 'job' => $job ] );
			return 0;
		}

		if ( isset( $args['user_id'] ) ) {
			$dispatch_reason = ( new RateLimiter( Cache::make() ) )->canDispatchHeavyJob(
				$job,
				$args,
				(int) $args['user_id']
			);

			if ( null !== $dispatch_reason ) {
				return 0;
			}
		}

		$args['blog_id'] = get_current_blog_id();
		$args            = self::addDedupeIdentity( $args );
		$fingerprint     = $this->makeFingerprint( $job, $args );

		$lock = $this->lock_service->acquireDispatch( $fingerprint );

		if ( ! $lock['acquired'] ) {
			return (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
		}

		$action_id = 0;

		try {

			$existing_action_id = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
			if ( $existing_action_id > 0 && $this->isActionStillLive( $existing_action_id ) ) {
				return $existing_action_id;
			}

			self::ksortRecursive( $args );

			// args (vendor/woocommerce/action-scheduler/.../ActionScheduler_DBStore.php:

			$action_id = (int) as_schedule_single_action(
				time() + max( 0, $delay ),
				$job,
				[ $args ],
				$this->asGroup(),
				false
			);

			if ( $action_id > 0 ) {
				set_transient( Keys::transient( 'aid_' . $fingerprint ), $action_id, DAY_IN_SECONDS );
			} else {
				$action_id = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
			}
		} finally {
			$this->lock_service->releaseDispatch( $fingerprint, $lock['option_fallback'] );
		}

		return $action_id;
	}

	/** @param array<string, mixed> $args */
	public function releaseFingerprint(string $job, array $args): void {
		$fingerprint = $this->makeFingerprint( $job, $args );
		delete_transient( Keys::transient( 'aid_' . $fingerprint ) );
	}

	/**
	 * @param array<string, mixed> $raw_args
	 */

	public function unschedule(string $job, array $raw_args): void {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		as_unschedule_action( $job, [ $this->enrichedForFingerprint( $raw_args ) ], $this->asGroup() );
	}

	/**
	 * @param array<string, mixed> $raw_args
	 * @param callable(array<string, mixed>): array<string, mixed> $work
	 */

	public function runGuarded(string $job, array $raw_args, callable $work): string {
		$blog_id = (int) ( $raw_args['blog_id'] ?? get_current_blog_id() );

		/** @var self::RESULT_* $result */
		$result = $this->runInBlogContext(
			$blog_id,
			function (callable $restore_now) use ($job, $raw_args, $work): string {
				$enriched_args = $this->enrichedForFingerprint( $raw_args );
				$fingerprint   = $this->makeFingerprint( $job, $enriched_args );
				$action_id     = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );

				if ( ! $this->lock_service->acquireExecution( $fingerprint )['acquired'] ) {
					Logger::warning( 'job_guarded_execution_lock_busy', [ 'job' => $job ] );
					$restore_now();

					return self::RESULT_BUSY;
				}

				if ( $action_id > 0 && false !== get_option( Keys::jobResult( $action_id ) ) ) {
					Logger::warning( 'job_guarded_already_completed_by_concurrent_runner', [ 'job' => $job ] );
					$this->lock_service->releaseExecution( $fingerprint );
					$restore_now();

					return self::RESULT_ALREADY_DONE;
				}

				try {
					$result_payload = $work( $enriched_args );

					if ( $action_id > 0 ) {
						$this->storeJobResult( $action_id, $result_payload );
					}

					delete_transient( Keys::transient( 'aid_' . $fingerprint ) );

					return self::RESULT_EXECUTED;
				} finally {
					$restore_now();
					$this->lock_service->releaseExecution( $fingerprint );
				}
			}
		);

		return $result;
	}

	/**
	 * @param array<string, mixed> $payload
	 */

	private function storeJobResult(int $action_id, array $payload): void {
		$filtered = array_filter( $payload, static fn ($value): bool => $value !== null );
		$filtered['_created_at'] = time();
		update_option( Keys::jobResult( $action_id ), $filtered, false );
	}

	/**
	 * @param array<string, mixed> $raw_args
	 * @return array<string, mixed>
	 */

	private function enrichedForFingerprint(array $raw_args): array {
		$args = $raw_args;

		$args['blog_id'] ??= get_current_blog_id();
		$args              = self::addDedupeIdentity( $args );
		self::ksortRecursive( $args );

		return $args;
	}

	/** @param array<string, mixed> $args */
	public function getActionId(string $job, array $args): int {
		$fingerprint = $this->makeFingerprint( $job, $args );

		return (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */

	public function storeResultForAction(int $action_id, array $payload): void {
		$this->storeJobResult( $action_id, $payload );
	}

	/** @param array<string, mixed> $args */
	public function dispatchRecurring(string $job, int $interval_seconds, array $args = []): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			Logger::warning( 'job_dispatch_recurring_skipped_no_action_scheduler', [ 'job' => $job ] );
			return;
		}

		$args['blog_id'] = get_current_blog_id();

		if ( ! as_has_scheduled_action( $job, [ $args ], $this->asGroup() ) ) {
			as_schedule_recurring_action(
				time() + $interval_seconds,
				$interval_seconds,
				$job,
				[ $args ],
				$this->asGroup()
			);
		}
	}

	public static function groupForBlog(int $blog_id): string {
		return 'plathix_' . $blog_id;
	}

	/**
	 * @return array<int, array<string, int>>
	 */

	public static function recurringUnscheduleArgs(int $blog_id): array {
		return [ [ 'blog_id' => $blog_id ] ];
	}

	/** @return array{status: string, result: mixed, attempts: int} */
	public function getStatus(int $action_id): array {
		return $this->status_repository->get( $action_id );
	}

	/** @param array<string, mixed> $args */
	public function handleImport(array $args = []): void {
		$this->import_runner->run(
			$args,
			[ $this, 'runInBlogContext' ],
			[ $this, 'releaseFingerprint' ]
		);
	}

	/** @param array<string, mixed> $args */
	public function handleReorder(array $args = []): void {
		$this->reorder_runner->run(
			$args,
			[ $this, 'runInBlogContext' ],
			[ $this, 'releaseFingerprint' ]
		);
	}

	/** @param array<string, mixed> $args */
	public function handleOrphanCleanup(array $args = []): void {
		$this->orphan_runner->run( $args, [ $this, 'runInBlogContext' ] );
	}

	/** @param array<string, mixed> $args */
	public function handleFolderCountReconcile(array $args = []): void {
		$this->folder_count_reconcile_runner->run( $args, [ $this, 'runInBlogContext' ] );
	}

	/** @param array<string, mixed> $args */
	public function handleCleanup(array $args = []): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$this->runInBlogContext(
			$blog_id,
			function () {
				$this->cleanup_runner->run( [ $this, 'getTempDir' ] );
			}
		);
	}

	/** @param array<string, mixed> $args */
	public function handleImportCheckpointCleanup(array $args = []): void {
		$this->import_checkpoint_cleanup_runner->run( $args, [ $this, 'runInBlogContext' ] );
	}


	private function asGroup(): string {
		return self::groupForBlog( get_current_blog_id() );
	}

	private function isActionStillLive(int $action_id): bool {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$status = \ActionScheduler::store()->get_status( $action_id );
		} catch ( \Throwable $e ) {
			return false;
		}

		return in_array( $status, [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ], true );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */

	public static function addDedupeIdentity(array $args): array {

		if ( isset( $args['user_id'] ) && ! isset( $args['_dedupe_identity'] ) ) {
			$args['_dedupe_identity'] = IdentityKeyResolver::resolve( (int) $args['user_id'] );

			$token_id = apply_filters( 'plathix/infrastructure/current_service_token_id', null );
			if ( is_string( $token_id ) && $token_id !== '' ) {
				$args['created_by_token_id'] = $token_id;
			}
		}

		return $args;
	}

	/** @param array<string, mixed> $args */
	public function makeFingerprint(string $job, array $args): string {
		$blog_id = get_current_blog_id();

		array_walk_recursive(
			$args,
			static function (mixed &$value): void {
				$value = (string) $value;
			}
		);

		self::ksortRecursive( $args );

		return 'plx_jfp_' . hash( 'sha256', $blog_id . '|' . $job . '|' . wp_json_encode( $args ) );
	}

	/**
	 * @template T
	 * @param callable(callable(): void): T $callback
	 * @return T
	 */

	public function runInBlogContext(int $blog_id, callable $callback): mixed {
		$multisite = is_multisite();
		$restored  = false;

		if ( $multisite ) {
			switch_to_blog( $blog_id );
		}

		$restore_now = static function () use (&$restored, $multisite): void {
			if ( $multisite && ! $restored ) {
				restore_current_blog();
				$restored = true;
			}
		};

		try {
			return $callback( $restore_now );
		} finally {
			$restore_now();
		}
	}

	/**
	 * @param array<mixed> $arr
	 */

	public static function ksortRecursive(array &$arr): void {
		ksort( $arr );

		foreach ( $arr as &$value ) {
			if ( is_array( $value ) ) {
				self::ksortRecursive( $value );
			}
		}
	}
}
