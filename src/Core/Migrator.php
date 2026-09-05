<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\JobLockService;

class Migrator
{
	public static function run(string $current_version): void {
		$stored = (string) get_option('plathix_db_version', '0.0.0');

		if ( version_compare($stored, $current_version, '>=') ) {
			return;
		}

		$lock_service = new JobLockService();
		$lock_result  = $lock_service->acquire_execution('migrator');

		if ( ! $lock_result['acquired'] ) {
			return;
		}

		try {
			// Placeholder for forward migrations.

			update_option('plathix_db_version', $current_version);
		} finally {
			$lock_service->release_execution('migrator');
		}
	}
}
