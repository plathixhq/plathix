<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;

class Migrator
{
	public static function run(string $current_version): void {
		$stored = (string) get_option('plathix_db_version', '0.0.0');

		if ( version_compare($stored, $current_version, '>=') ) {
			return;
		}

		$lock_service = new JobLockService();
		$lock_result  = $lock_service->acquireExecution('migrator');

		if ( ! $lock_result['acquired'] ) {
			Logger::warning( 'migrator_execution_lock_busy', [ 'fingerprint' => 'migrator' ] );
			return;
		}

		try {
			// Placeholder for forward migrations.

			update_option('plathix_db_version', $current_version);
		} finally {
			$lock_service->releaseExecution('migrator');
		}
	}
}
