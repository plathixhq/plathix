<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class TableExistenceChecker
{
	/**
	 * @param string $tableName
	 */

	public static function exists(string $tableName): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES probe; no WP API exposes table existence, table name bound via %s
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) );

		if ( null === $result ) {
			Logger::warning( 'table_existence_check_sql_failed', [ 'table' => $tableName ] );
		}

		return $result === $tableName;
	}
}
