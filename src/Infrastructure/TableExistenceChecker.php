<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * [internal] ([internal]): единственный владелец `SHOW TABLES`-пробы существования таблицы.
 *
 * До этого класса `SHOW TABLES LIKE %s` вызывался напрямую в трёх местах (SystemInfoProvider,
 * RealMediaLib, DataWiper) — пять вызовов с идентичным `phpcs:ignore`-обоснованием. Тот же
 * диагноз, что уже применён к `GET_LOCK`/`RELEASE_LOCK` в {@see DbAdvisoryLock}: одинаковый
 * текст обоснования, повторённый N раз, означал не N решений, а одну невынесенную обёртку.
 *
 * WordPress не имеет API для проверки существования произвольной таблицы — ни `WP_Query`,
 * ни options/transients это не дают. Поэтому сам `SHOW TABLES` остаётся, инкапсулируется он.
 *
 * `DataWiper::clear_action_scheduler_groups()` НЕ переведён на этот класс: там `SHOW TABLES`
 * — guard-условие внутри единого `phpcs:disable`/`enable` teardown-блока (проверка + DELETE —
 * "one logical operation" по авторскому комментарию), не самостоятельная переиспользуемая
 * операция — вынос разорвал бы сознательно спроектированную транзакционную границу.
 */
final class TableExistenceChecker
{
	/**
	 * Проверяет существование таблицы по полному имени (с префиксом).
	 *
	 * @param string $table_name Полное имя таблицы, включая `$wpdb->prefix` — вызывающая
	 *                            сторона строит его сама, метод не знает деталей построения.
	 */
	public static function exists(string $table_name): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES probe; no WP API exposes table existence, table name bound via %s
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}
}
