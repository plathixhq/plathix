<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Infrastructure\Cache;
use Plathix\User\Preferences;

/**
 * Статистика избранных папок по всем пользователям.
 * Выделено из HomeDashboardData (god-object → узкие сервисы по источнику данных).
 *
 * Кэшируется на час (статистика дашборда не реалтайм; сброс по TTL — как у disk_usage).
 */
class UserFavoritesService
{
	/** @return array{total_unique_folders: int} */
	public function stats(): array {
		$cache     = Cache::make();
		$cache_key = $cache->versioned_key( Cache::DASHBOARD_STATS_GROUP, 'favorites_stats' );
		$cached    = $cache->get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['total_unique_folders'] ) ) {
			return $cached;
		}

		// Избранное хранится посегментно по post_type: ключ user_meta plathix_favorites_<pt>
		// (Preferences::get_favorites($uid, $pt)). Прежний вызов без $post_type читал ключ
		// plathix_favorites без суффикса, которого в реальности нет — статистика всегда была 0.
		// Собираем по всем включённым типам (тот же список, что использует дашборд).
		$post_types = [ 'attachment' ]; // CTAN-201: attachment-native

		$all_ids = $this->collect_favorite_ids( $post_types );

		// [internal] (класс #692/#798): null = реальная SQL-ошибка, отличная от
		// валидно-пустого результата — не кэшируем 0, который мы знаем, что неверен.
		if ( null === $all_ids ) {
			return [ 'total_unique_folders' => 0 ];
		}

		// Уникальность по объединённому списку id: одна папка, избранная в двух типах,
		// считается один раз.
		$stats = [ 'total_unique_folders' => count( array_unique( $all_ids ) ) ];
		$cache->set( $cache_key, $stats, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * [internal]: прежняя реализация грузила ВСЕХ пользователей сайта (get_users() без
	 * лимита) и прайминговала их usermeta целиком через update_meta_cache('user', $ids) —
	 * WP core читает SELECT meta_key, meta_value FROM wp_usermeta WHERE umeta_id IN (...)
	 * БЕЗ фильтра по meta_key, то есть тянет всю usermeta каждого пользователя (все
	 * плагины, все роли), не только favorites-ключи. На сайтах с 10k-50k пользователей
	 * это сотни тысяч — миллион строк, дважды материализованных в PHP-массиве одного
	 * HTTP-рендера ([internal] закрыл N+1, но этой ценой). Прямой запрос по meta_key LIKE
	 * читает только строки, реально принадлежащие Plathix favorites — у большинства
	 * пользователей таких строк 0. Паттерн повторяет DataWiper::wipe_user_meta()
	 * (src/Modules/DataWipe/DataWiper.php) — тот же класс прямого $wpdb->usermeta запроса.
	 *
	 * @param list<string> $post_types
	 * @return list<int>|null null означает SQL-ошибку ([internal]) — отличается от [] (валидно нет избранного)
	 */
	private function collect_favorite_ids(array $post_types): ?array {
		global $wpdb;

		$pattern = $wpdb->esc_like( Preferences::FAVORITES_META . '_' ) . '%' . $wpdb->esc_like( Preferences::blog_suffix() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- meta_key pattern bound via prepare(); нет user input, только фиксированный префикс и blog_suffix (int|'' из get_current_blog_id())
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $pattern ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// [internal]: $wpdb->get_results() возвращает null на реальной SQL-ошибке —
		// пропагируем это наверх, а не глотаем через (array) null = [].
		if ( null === $rows ) {
			return null;
		}

		$suffix       = Preferences::blog_suffix();
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
	 * Инвалидирует кэш favorites-статистики ([internal]). Подписчик события plathix/favorites/changed:
	 * владелец кэша владеет инвалидацией, ключ инкапсулирован здесь (тот же, что в stats()). Иначе
	 * телеметрия дашборда устаревала до часа после изменения фаворита.
	 *
	 * @param int    $user_id  не используется (кэш общий, не per-user) — принят для совместимости
	 *                         с сигнатурой хука plathix/favorites/changed($user_id, $post_type).
	 * @param string $post_type не используется (один общий ключ на все типы) — то же.
	 */
	public static function invalidate(int $user_id = 0, string $post_type = ''): void {
		Cache::make()->delete_group( Cache::DASHBOARD_STATS_GROUP );
	}
}
