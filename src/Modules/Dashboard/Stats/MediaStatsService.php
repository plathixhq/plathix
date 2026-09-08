<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Core\AttachmentVisibility;
use Plathix\Infrastructure\Cache;

class MediaStatsService
{

	private const TTL = HOUR_IN_SECONDS;

	/** @return array<int, array{mime: string, label: string, count: int, pct: float}> */
	public function mimeStats(): array {
		$cache  = Cache::make();
		$key    = $cache->versionedKey( Cache::DASHBOARD_STATS_GROUP, 'mimeStats' );
		$cached = $cache->get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$visible_predicate = AttachmentVisibility::sqlPredicate( 'p' );
		$status_predicate  = AttachmentVisibility::statusInPredicate( [ 'inherit' ], 'p' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $visible_predicate/$status_predicate are self-built fragments (esc_sql inside), $wpdb->posts is a core table name; no user input
		$rows = $wpdb->get_results(
			"SELECT post_mime_type, COUNT(*) as cnt
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment' AND {$status_predicate}
			   AND {$visible_predicate}
			 GROUP BY post_mime_type
			 ORDER BY cnt DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( null === $rows ) {
			return [];
		}

		if ( empty( $rows ) ) {
			$cache->set( $key, [], self::TTL );
			return [];
		}

		$groups = [
			'image/jpeg'      => 'JPEG',
			'image/png'       => 'PNG',
			'image/webp'      => 'WebP',
			'image/gif'       => 'GIF',
			'image/svg+xml'   => 'SVG',
			'video/mp4'       => 'MP4',
			'video/quicktime' => 'MOV',
			'video/webm'      => 'WebM',
			'audio/mpeg'      => 'MP3',
			'audio/wav'       => 'WAV',
			'application/pdf' => 'PDF',
		];

		$buckets = [];
		$other   = 0;
		$total   = 0;

		foreach ( $rows as $row ) {
			$mime  = (string) $row['post_mime_type'];
			$count = (int) $row['cnt'];
			$total += $count;

			if ( isset( $groups[ $mime ] ) ) {
				$bucket_key = $mime;
				if ( ! isset( $buckets[ $bucket_key ] ) ) {
					$buckets[ $bucket_key ] = [ 'mime' => $mime, 'label' => $groups[ $mime ], 'count' => 0 ];
				}
				$buckets[ $bucket_key ]['count'] += $count;
			} else {
				$other += $count;
			}
		}

		uasort( $buckets, fn($a, $b) => $b['count'] - $a['count'] );
		$result = array_values( $buckets );

		if ( count( $result ) > 4 ) {
			$top    = array_slice( $result, 0, 4 );
			$rest   = array_sum( array_column( array_slice( $result, 4 ), 'count' ) ) + $other;
			$result = $top;
			if ( $rest > 0 ) {
				$result[] = [ 'mime' => 'other', 'label' => __( 'Other', 'plathix' ), 'count' => $rest ];
			}
		} elseif ( $other > 0 ) {
			$result[] = [ 'mime' => 'other', 'label' => __( 'Other', 'plathix' ), 'count' => $other ];
		}

		$result = $this->distributePercentages( $result, $total );

		$cache->set( $key, $result, self::TTL );

		/** @var array<int, array{mime: string, label: string, count: int, pct: float}> $result pct added by-ref above; phpstan does not track it into the shape. */
		return $result;
	}

	/**
	 * @param array<int, array{mime: string, label: string, count: int}> $items
	 * @return array<int, array{mime: string, label: string, count: int, pct: float}>
	 */

	private function distributePercentages(array $items, int $total): array {
		$remainders = [];
		$sum_floor  = 0;

		foreach ( $items as $index => $item ) {
			$exact                 = $item['count'] / $total * 100;
			$items[ $index ]['pct'] = floor( $exact );
			$remainders[ $index ]  = $exact - floor( $exact );
			$sum_floor             += (int) $items[ $index ]['pct'];
		}

		$missing = 100 - $sum_floor;

		$order   = array_keys( $remainders );
		$epsilon = 1e-9;
		usort(
			$order,
			function ($a, $b) use ($remainders, $epsilon) {
				$diff = $remainders[ $b ] - $remainders[ $a ];
				if ( abs( $diff ) < $epsilon ) {
					return $a <=> $b;
				}
				return $diff <=> 0;
			}
		);

		for ( $i = 0; $i < $missing; $i++ ) {
			$items[ $order[ $i ] ]['pct'] += 1;
		}

		return $items;
	}

	/** @return array{last_7: int, last_30: int, by_day: list<array{date: string, count: int}>} */
	public function uploadActivity(): array {
		$cache  = Cache::make();
		$key    = $cache->versionedKey( Cache::DASHBOARD_STATS_GROUP, 'uploadActivity' );
		$cached = $cache->get( $key );
		if ( is_array( $cached ) ) {
			/** @var array{last_7: int, last_30: int, by_day: list<array{date: string, count: int}>} $cached */
			return $cached;
		}

		global $wpdb;

		$visible_predicate = AttachmentVisibility::sqlPredicate( 'p' );
		$status_predicate  = AttachmentVisibility::statusInPredicate( [ 'inherit' ], 'p' );

		$cutoff_30 = gmdate( 'Y-m-d H:i:s', (int) strtotime( current_time( 'mysql' ) . ' -30 days' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $visible_predicate/$status_predicate are self-built fragments (esc_sql inside), $wpdb->posts is a core table name; no user input. $cutoff_30 goes through prepare() as a bound %s parameter below (not interpolated).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(post_date) as day, COUNT(*) as cnt
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = 'attachment'
				   AND {$status_predicate}
				   AND p.post_date >= %s
				   AND {$visible_predicate}
				 GROUP BY DATE(post_date)
				 ORDER BY day ASC",
				$cutoff_30
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( null === $rows ) {
			return [ 'last_7' => 0, 'last_30' => 0, 'by_day' => [] ];
		}

		$by_day  = [];
		$last_7  = 0;
		$last_30 = 0;

		$cutoff7 = gmdate( 'Y-m-d', (int) strtotime( current_time( 'mysql' ) . ' -7 days' ) );

		foreach ( (array) $rows as $row ) {
			$count    = (int) $row['cnt'];
			$by_day[] = [ 'date' => (string) $row['day'], 'count' => $count ];
			$last_30 += $count;
			if ( (string) $row['day'] >= $cutoff7 ) {
				$last_7 += $count;
			}
		}

		$result = compact( 'last_7', 'last_30', 'by_day' );
		$cache->set( $key, $result, self::TTL );

		return $result;
	}
}
