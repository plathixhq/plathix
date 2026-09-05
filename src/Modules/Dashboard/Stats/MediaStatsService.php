<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Core\AttachmentVisibility;
use Plathix\Infrastructure\Cache;

/**
 * Медиа-статистика дашборда из $wpdb: типы файлов (mime), активность загрузок.
 * Связная подсистема, выделенная из HomeDashboardData (god-object → узкие сервисы по
 * источнику данных). [internal]: подсчёт использования gallery-шорткодов уехал в PRO
 * целиком (PlathixPro\Modules\Gallery\ShortcodeUsageScanner::dashboard_stats()) — Free
 * не регистрирует эти шорткоды и не рендерит их.
 */
class MediaStatsService
{
	/**
	 * Статистика дашборда не реалтайм — каждый тяжёлый запрос кэшируется на час
	 * (сброс по TTL, как у disk_usage). Ключи раздельные для точечной инвалидации.
	 */
	private const TTL = HOUR_IN_SECONDS;

	/** @return array<int, array{mime: string, label: string, count: int, pct: float}> */
	public function mime_stats(): array {
		$cache  = Cache::make();
		$key    = $cache->versioned_key( Cache::DASHBOARD_STATS_GROUP, 'mime_stats' );
		$cached = $cache->get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// Считаем только файлы, реально показываемые в медиа-гриде: служебные вложения
		// генераторов (Elementor screenshots, помеченные `_elementor_is_screenshot`) скрыты
		// из библиотеки, поэтому не должны раздувать dashboard-статистику ([internal]). Правило
		// видимости — единый источник AttachmentVisibility ([internal]), тот же,
		// что применяют FolderCountService и все грид-синхронные счётчики. Статус-allowlist
		// ('inherit') — тот же единый источник, statusInPredicate() ([internal]).
		$visible_predicate = AttachmentVisibility::sql_predicate( 'p' );
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

		// [internal] (класс #692/#798): $wpdb->get_results() возвращает null на реальной
		// SQL-ошибке, [] на валидно-пустом результате — эти случаи нельзя схлопывать в
		// один "нет данных": null не кэшируем, следующее чтение получит новый шанс.
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
				$key = $mime;
				if ( ! isset( $buckets[ $key ] ) ) {
					$buckets[ $key ] = [ 'mime' => $mime, 'label' => $groups[ $mime ], 'count' => 0 ];
				}
				$buckets[ $key ]['count'] += $count;
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

		// $total > 0 гарантирован: $rows не пуст (проверено выше), значит хотя бы одна
		// строка дала count > 0, попавший либо в $buckets, либо в $other.
		$result = $this->distribute_percentages( $result, $total );

		$cache->set( $key, $result, self::TTL );

		/** @var array<int, array{mime: string, label: string, count: int, pct: float}> $result pct added by-ref above; phpstan does not track it into the shape. */
		return $result;
	}

	/**
	 * Largest remainder method ([internal]): независимое округление каждой доли по
	 * отдельности может дать сумму != 100 (например 102% на реальных данных). Округляем
	 * все доли вниз, затем раздаём недостающие процентные пункты элементам с наибольшим
	 * дробным остатком — по одному пункту, по убыванию остатка, пока сумма не станет 100.
	 *
	 * @param array<int, array{mime: string, label: string, count: int}> $items
	 * @return array<int, array{mime: string, label: string, count: int, pct: float}>
	 */
	private function distribute_percentages(array $items, int $total): array {
		$remainders = [];
		$sum_floor  = 0;

		foreach ( $items as $index => $item ) {
			$exact                 = $item['count'] / $total * 100;
			$items[ $index ]['pct'] = floor( $exact );
			$remainders[ $index ]  = $exact - floor( $exact );
			$sum_floor             += (int) $items[ $index ]['pct'];
		}

		$missing = 100 - $sum_floor;

		// Стабильный tie-break: при равном остатке раздаём в порядке $items (уже
		// отсортирован по count убыв.), не полагаясь на неявную стабильность uasort/usort.
		// Остатки сравниваются с эпсилон-допуском: разные дроби (напр. 197/382 и 6/382) могут
		// давать математически равную дробную часть, различающуюся лишь на float-погрешность
		// в последних значащих цифрах — без допуска такая погрешность решала бы порядок раздачи
		// вместо явного tie-break по индексу.
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
	public function upload_activity(): array {
		$cache  = Cache::make();
		$key    = $cache->versioned_key( Cache::DASHBOARD_STATS_GROUP, 'upload_activity' );
		$cached = $cache->get( $key );
		if ( is_array( $cached ) ) {
			/** @var array{last_7: int, last_30: int, by_day: list<array{date: string, count: int}>} $cached */
			return $cached;
		}

		global $wpdb;

		// Та же видимость грида, что и mime_stats ([internal]): не считаем служебные
		// generator-hidden attachments в активности загрузок. Единый источник — AttachmentVisibility.
		// Статус-allowlist ('inherit') — тот же единый источник, statusInPredicate() ([internal]).
		$visible_predicate = AttachmentVisibility::sql_predicate( 'p' );
		$status_predicate  = AttachmentVisibility::statusInPredicate( [ 'inherit' ], 'p' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $visible_predicate/$status_predicate are self-built fragments (esc_sql inside), $wpdb->posts is a core table name; no user input
		$rows = $wpdb->get_results(
			"SELECT DATE(post_date) as day, COUNT(*) as cnt
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment'
			   AND {$status_predicate}
			   AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			   AND {$visible_predicate}
			 GROUP BY DATE(post_date)
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// [internal] (класс #692/#798): null = реальная SQL-ошибка, отличная от валидно
		// пустого результата — не кэшируем нули, которые мы знаем, что неверны.
		if ( null === $rows ) {
			return [ 'last_7' => 0, 'last_30' => 0, 'by_day' => [] ];
		}

		$by_day  = [];
		$last_7  = 0;
		$last_30 = 0;
		$cutoff7 = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

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
