<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Health;

use Plathix\Infrastructure\CronStatusResolver;
use Plathix\Infrastructure\Features;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\TempDirectory;

final class HealthCheckRegistry
{
	/**
	 * @return array<int, array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}>
	 */
	public function checks(): array {
		return [
			$this->checkCron(),
			$this->checkTempDirWritable(),
			$this->checkTempDirSize(),
			$this->checkStuckJobs(),
			$this->checkBootIntegrity(),
			$this->checkSvgSanitizer(),
			$this->checkPresetsDirGuard(),
		];
	}

	/**
	 * @return array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}
	 */

	public function svgSanitizer(): array {
		return $this->checkSvgSanitizer();
	}

	/**
	 * @return array<int, string>
	 */

	public function issues(): array {
		$labels = [];
		foreach ( $this->checks() as $check ) {
			if ( 'error' === $check['severity'] && ! $check['ok'] ) {
				$labels[] = $check['value'];
			}
		}
		return $labels;
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function checkCron(): array {
		$status = ( new CronStatusResolver() )->resolve();

		if ( ! $status['disabled'] ) {
			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Enabled', 'plathix' ),
			];
		}

		if ( $status['idle'] ) {

			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'DISABLE_WP_CRON, Action Scheduler idle (nothing scheduled)', 'plathix' ),
			];
		}

		if ( ! $status['stalled'] ) {
			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'DISABLE_WP_CRON but Action Scheduler active', 'plathix' ),
			];
		}

		return [
			'key'      => 'cron',
			'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
			'ok'       => false,
			'severity' => 'error',
			'value'    => __( 'WP-Cron disabled, Action Scheduler stalled — ZIP/import jobs may not run', 'plathix' ),
		];
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function checkTempDirWritable(): array {
		$temp_dir = ( new TempDirectory() )->path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only diagnostic probe of the plugin's own temp dir for the health registry; reports status only, writes nothing.
		$ok = '' !== $temp_dir && ( ! is_dir( $temp_dir ) || is_writable( $temp_dir ) );

		return [
			'key'      => 'temp_dir_writable',
			'label'    => __( 'Temp Dir Writable', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				? __( 'Writable', 'plathix' )
				: __( 'Temp directory is not writable. ZIP downloads will fail.', 'plathix' ),
		];
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function checkTempDirSize(): array {
		$temp_dir = ( new TempDirectory() )->path();
		$max_size = (int) apply_filters( 'plathix/infrastructure/temp_dir_max_bytes', 2 * GB_IN_BYTES );

		$zips = is_dir( $temp_dir ) ? ( glob( rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.zip' ) ?: [] ) : [];
		$size = array_sum( array_map( 'filesize', $zips ) );
		$ok   = $size <= $max_size;

		return [
			'key'      => 'temp_dir_size',
			'label'    => __( 'Temp Dir Size', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				? sprintf(
					/* translators: 1: current size, 2: limit. */
					__( '%1$s of %2$s limit', 'plathix' ),
					size_format( $size ),
					size_format( $max_size )
				)
				: sprintf(
					/* translators: 1: current size, 2: limit. */
					__( 'Temp directory exceeds size limit (%1$s of %2$s) — cleanup job may be stalled.', 'plathix' ),
					size_format( $size ),
					size_format( $max_size )
				),
		];
	}

	private const STUCK_JOB_AGE_THRESHOLD = 10 * MINUTE_IN_SECONDS;

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function checkStuckJobs(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return [
				'key'      => 'stuck_jobs',
				'label'    => __( 'Stuck Running Jobs', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Action Scheduler not available', 'plathix' ),
			];
		}

		$caps       = (array) apply_filters( 'plathix/jobs/heavy_caps', [ JobDispatcher::JOB_IMPORT => 2 ] );
		$zip_cap    = (int) ( $caps[ JobDispatcher::JOB_ZIP_GENERATE ] ?? 5 );
		$import_cap = (int) ( $caps[ JobDispatcher::JOB_IMPORT ] ?? 3 );

		[ $zip_running, $zip_stuck ]       = $this->runningAndStuck( JobDispatcher::JOB_ZIP_GENERATE, $zip_cap );
		[ $import_running, $import_stuck ] = $this->runningAndStuck( JobDispatcher::JOB_IMPORT, $import_cap );
		$ok                                = ! $zip_stuck && ! $import_stuck;

		return [
			'key'      => 'stuck_jobs',
			'label'    => __( 'Stuck Running Jobs', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				/* translators: 1: running ZIP jobs count, 2: running import jobs count. */
				? sprintf( __( 'ZIP: %1$d running, import: %2$d running', 'plathix' ), $zip_running, $import_running )
				/* translators: 1: running ZIP jobs count, 2: running import jobs count. */
				: sprintf( __( 'Possibly stuck — ZIP: %1$d, import: %2$d', 'plathix' ), $zip_running, $import_running ),
		];
	}

	/**
	 * @return array{0:int, 1:bool}
	 */

	private function runningAndStuck(string $hook, int $cap): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return [ 0, false ];
		}

		$ids = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => max( 1, $cap + 1 ),
			],
			'ids'
		);

		$running = count( $ids );
		$age     = null;

		if ( $running > 0 && class_exists( '\\ActionScheduler' ) ) {
			try {
				$action = \ActionScheduler::store()->fetch_action( (int) reset( $ids ) );
				if ( ! ( $action instanceof \ActionScheduler_NullAction ) ) {
					$date = $action->get_schedule()->get_date();
					if ( $date instanceof \DateTime ) {
						$age = time() - $date->getTimestamp();
					}
				}
			} catch ( \Throwable ) {

			}
		}

		return [ $running, self::isStuck( $running, $cap, $age ) ];
	}

	private static function isStuck(int $running, int $cap, ?int $oldest_running_age_seconds): bool {
		if ( $running > $cap ) {
			return true;
		}

		return null !== $oldest_running_age_seconds && $oldest_running_age_seconds > self::STUCK_JOB_AGE_THRESHOLD;
	}

	/**
	 * @return array{key:string, label:string, ok:bool, severity:'error', value:string}
	 */

	private function checkBootIntegrity(): array {
		$recovered = 1 === (int) get_option( 'plathix_boot_recovered_lazily', 0 );

		return [
			'key'      => 'boot_integrity',
			'label'    => __( 'Boot Integrity', 'plathix' ),
			'ok'       => ! $recovered,
			'severity' => 'error',
			'value'    => $recovered
				? __( 'Init interrupted by another plugin — system terms recovered lazily. Check for a plugin fataling on the init hook.', 'plathix' )
				: __( 'Normal', 'plathix' ),
		];
	}

	/**
	 * @return array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}
	 */

	private function checkSvgSanitizer(): array {
		$svg_enabled = Features::isEnabled( 'svg' );
		[ $ok, $value ] = $this->svgSanitizerStatus();

		return [
			'key'      => 'svgSanitizer',
			'label'    => __( 'SVG Sanitizer', 'plathix' ),
			'ok'       => $ok,
			'severity' => $svg_enabled ? 'error' : 'ignored',
			'value'    => $value,
		];
	}

	/**
	 * @return array{0:bool, 1:string}
	 */

	private function svgSanitizerStatus(): array {
		if ( ! class_exists( \enshrined\svgSanitize\Sanitizer::class ) ) {
			return [ false, __( 'Missing or outdated', 'plathix' ) ];
		}

		if ( ! $this->isOwnSanitizerEngine( \enshrined\svgSanitize\Sanitizer::class ) ) {
			return [ false, __( 'Provided by another plugin — not verifiable', 'plathix' ) ];
		}

		return [ true, __( 'Available', 'plathix' ) ];
	}

	/**
	 * @param class-string $engineClass
	 */

	private function isOwnSanitizerEngine(string $engineClass): bool {
		try {
			$file = ( new \ReflectionClass( $engineClass ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return false;
		}

		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		$engineReal = realpath( $file );
		$pluginReal = defined( 'PLATHIX_PATH' ) ? realpath( PLATHIX_PATH ) : false;

		if ( false === $engineReal || false === $pluginReal ) {
			return false;
		}

		return TempDirectory::isUnderRoot( $engineReal, rtrim( $pluginReal, '/\\' ) );
	}

	/**
	 * @return array{key:string, label:string, ok:bool, severity:'error', value:string}
	 */

	private function checkPresetsDirGuard(): array {
		$upload = wp_upload_dir();
		$dir    = ! empty( $upload['basedir'] ) ? trailingslashit( $upload['basedir'] ) . 'plathix/presets' : '';

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return [
				'key'      => 'presets_dir_guard',
				'label'    => __( 'Presets Directory Guard', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Directory not created yet — guard will be added automatically on first preset upload.', 'plathix' ),
			];
		}

		$missing = [];
		if ( ! file_exists( trailingslashit( $dir ) . 'index.php' ) ) {
			$missing[] = 'index.php';
		}
		if ( ! file_exists( trailingslashit( $dir ) . '.htaccess' ) ) {
			$missing[] = '.htaccess';
		}

		return [
			'key'      => 'presets_dir_guard',
			'label'    => __( 'Presets Directory Guard', 'plathix' ),
			'ok'       => [] === $missing,
			'severity' => 'error',
			'value'    => [] === $missing
				? __( 'Protected', 'plathix' )
				: sprintf(
					/* translators: %s: comma-separated list of missing guard files, e.g. "index.php, .htaccess". */
					__( 'Guard incomplete — missing: %s', 'plathix' ),
					implode( ', ', $missing )
				),
		];
	}
}
