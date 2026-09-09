<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Infrastructure\Cache;
use Plathix\User\Preferences;

class UserFavoritesService
{
	/** @return array{total_unique_folders: int} */
	public function stats(): array {
		$cache     = Cache::make();
		$cache_key = $cache->versionedKey( Cache::DASHBOARD_STATS_GROUP, 'favorites_stats' );
		$cached    = $cache->get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['total_unique_folders'] ) ) {
			return $cached;
		}

		$post_types = [ 'attachment' ];


		$all_ids = $this->collectFavoriteIds( $post_types );

		if ( null === $all_ids ) {
			return [ 'total_unique_folders' => 0 ];
		}

		$stats = [ 'total_unique_folders' => count( array_unique( $all_ids ) ) ];
		$cache->set( $cache_key, $stats, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * @param list<string> $post_types
	 * @return list<int>|null
	 */

	private function collectFavoriteIds(array $post_types): ?array {
		global $wpdb;

		$pattern = $wpdb->esc_like( Preferences::FAVORITES_META . '_' ) . '%' . $wpdb->esc_like( Preferences::blogSuffix() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $pattern ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $rows ) {
			return null;
		}

		$suffix       = Preferences::blogSuffix();
		$allowed_keys = [];
		foreach ( $post_types as $post_type ) {
			$allowed_keys[ Preferences::FAVORITES_META . '_' . sanitize_key( $post_type ) . $suffix ] = true;
		}

		$all_ids = [];
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $allowed_keys[ $row['meta_key'] ] ) ) {
				continue;
			}
			$ids = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $ids ) ) {
				$all_ids = array_merge( $all_ids, array_map( 'intval', $ids ) );
			}
		}

		return $all_ids;
	}

	/**
	 * @param int    $user_id
	 * @param string $post_type
	 */

	public static function invalidate(int $user_id = 0, string $post_type = ''): void {
		Cache::make()->deleteGroup( Cache::DASHBOARD_STATS_GROUP );
	}
}
