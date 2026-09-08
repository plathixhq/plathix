<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Infrastructure\Keys;

class StorageStatsService
{

	private const SENTINEL_TTL = 5 * MINUTE_IN_SECONDS;

	public function diskUsage(): int {
		$cached = get_transient( Keys::transient( 'dashboard_disk_usage' ) );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] ?? '';
		if ( empty( $base_dir ) || ! is_dir( $base_dir ) ) {
			return $this->cacheAndReturn( -1, self::SENTINEL_TTL );
		}

		$bytes = 0;
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( \Throwable $e ) {

			return $this->cacheAndReturn( -1, self::SENTINEL_TTL );
		}

		return $this->cacheAndReturn( $bytes, DAY_IN_SECONDS );
	}

	private function cacheAndReturn(int $value, int $ttl): int {
		set_transient( Keys::transient( 'dashboard_disk_usage' ), $value, $ttl );

		return $value;
	}
}
