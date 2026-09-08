<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class DbAdvisoryLock
{
	/**
	 * @param string $name
	 * @param int    $timeout
	 */

	public static function acquire(string $name, int $timeout = 0): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; an atomic DB primitive with no WP API equivalent, and caching a lock would defeat its purpose
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		return $result === '1';
	}

	public static function release(string $name): void
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; an atomic DB primitive with no WP API equivalent, and caching a lock would defeat its purpose
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	public static function isSupported(): bool
	{
		$probe = 'plathix_sysinfo_test';

		if ( ! self::acquire( $probe, 0 ) ) {
			return false;
		}

		self::release( $probe );

		return true;
	}
}
