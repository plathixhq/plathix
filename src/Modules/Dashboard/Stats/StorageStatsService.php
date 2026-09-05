<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Infrastructure\Keys;

/**
 * Дисковая статистика дашборда: суммарный размер папки uploads.
 * Тяжёлый рекурсивный обход ФС, кэшируется на сутки (transient).
 * Выделено из HomeDashboardData (god-object → узкие сервисы по источнику данных).
 */
class StorageStatsService
{
	/**
	 * Короткий TTL для сентинела `-1` (каталог недоступен / ошибка обхода). Ошибочное
	 * состояние должно самовосстанавливаться быстрее валидной статистики (DAY_IN_SECONDS):
	 * если доступ к uploads вернётся, дашборд подхватит реальный размер уже через минуты,
	 * а не через сутки. При этом сам сентинел кешируется — иначе КАЖДЫЙ заход на дашборд
	 * заново гонял бы рекурсивный обход ФС по недоступному/сетевому storage (удар по TTFB).
	 */
	private const SENTINEL_TTL = 5 * MINUTE_IN_SECONDS;

	/** Суммарный размер uploads в байтах; -1 если каталог недоступен. */
	public function disk_usage(): int {
		$cached = get_transient( Keys::transient( 'dashboard_disk_usage' ) );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] ?? '';
		if ( empty( $base_dir ) || ! is_dir( $base_dir ) ) {
			return $this->cache_and_return( -1, self::SENTINEL_TTL );
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
			// \Throwable, а не \Exception: битый симлинк / нет прав кидают \Error, который
			// \Exception не ловит — без этого fallback молча падал бы фаталом на дашборде.
			return $this->cache_and_return( -1, self::SENTINEL_TTL );
		}

		return $this->cache_and_return( $bytes, DAY_IN_SECONDS );
	}

	/** Единая точка выхода: кешировать результат (в т.ч. сентинел) и вернуть его. */
	private function cache_and_return(int $value, int $ttl): int {
		set_transient( Keys::transient( 'dashboard_disk_usage' ), $value, $ttl );

		return $value;
	}
}
