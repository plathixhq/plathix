<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * [internal] ([internal]): единственный владелец MySQL advisory-локов.
 *
 * До этого класса `GET_LOCK`/`RELEASE_LOCK` вызывались напрямую в четырёх файлах
 * (JobLockService, FolderRepository, AttachmentReplaceLock, SystemInfoProvider) — восемь
 * вызовов с восемью одинаковыми `phpcs:ignore`. Одинаковый текст обоснования, повторённый
 * девять раз, означал не девять решений, а одну невынесенную обёртку.
 *
 * WordPress не имеет API advisory-локов: ни `WP_Filesystem`, ни options/transients не дают
 * атомарного «взять или отказать» между параллельными PHP-процессами. Поэтому сам `GET_LOCK`
 * остаётся — это честная граница платформы. Меняется то, что он инкапсулирован: два
 * подавления живут здесь, а потребители про `$wpdb` больше не знают.
 *
 * Семантика MySQL, на которую опираются потребители:
 * - лок session-scoped: освобождается автоматически при закрытии соединения, поэтому
 *   «вечно висящий» лок после фатала невозможен;
 * - `GET_LOCK` возвращает '1' (взят), '0' (занят/таймаут) или NULL (ошибка/недоступен);
 *   NULL обязан трактоваться как отказ, а не как захват — иначе два процесса войдут в
 *   критическую секцию одновременно;
 * - `RELEASE_LOCK` на имя, которого сессия не держит, безвреден (возвращает '0'/NULL).
 */
final class DbAdvisoryLock
{
	/**
	 * Пытается взять именованный лок.
	 *
	 * @param string $name    Имя лока; уникальность обеспечивает вызывающая сторона.
	 * @param int    $timeout Секунд ожидания. 0 — немедленный отказ, если занят.
	 * @return bool true только при явном захвате ('1'). Занят, таймаут и недоступность
	 *              GET_LOCK одинаково дают false — fail-closed.
	 */
	public static function acquire(string $name, int $timeout = 0): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; an atomic DB primitive with no WP API equivalent, and caching a lock would defeat its purpose
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		return $result === '1';
	}

	/**
	 * Освобождает именованный лок. Безопасно вызывать, даже если лок не был взят.
	 */
	public static function release(string $name): void
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; an atomic DB primitive with no WP API equivalent, and caching a lock would defeat its purpose
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Диагностическая проба: доступен ли механизм advisory-локов на этом сервере.
	 *
	 * Берёт и сразу отпускает служебное имя. Используется страницей System Info, чтобы
	 * отличить «локи работают» от «GET_LOCK недоступен» (managed MySQL, прокси-слой).
	 */
	public static function is_supported(): bool
	{
		$probe = 'plathix_sysinfo_test';

		if ( ! self::acquire( $probe, 0 ) ) {
			return false;
		}

		self::release( $probe );

		return true;
	}
}
