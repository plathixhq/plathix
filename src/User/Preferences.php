<?php

declare(strict_types=1);

namespace Plathix\User;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Keys;

class Preferences
{
	private const OPEN_FOLDER_META = 'plathix_open_folder_id';
	public const FAVORITES_META    = 'plathix_favorites';

	// [internal]/#649: формула вынесена в Keys::blog_suffix() (единственный Free-владелец,
	// избавляет от дословной копии тела рядом с HomeDashboardPage::blog_scoped_meta_key()).
	// Сигнатура и поведение этого метода не меняются — внешние потребители
	// (UserFavoritesService и др.) продолжают вызывать его как раньше.
	public static function blog_suffix(): string {
		return Keys::blog_suffix();
	}

	public static function get_open_folder_id(int $user_id, string $post_type = ''): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$key = $post_type ? self::OPEN_FOLDER_META . '_' . sanitize_key($post_type) : self::OPEN_FOLDER_META;
		$key .= self::blog_suffix();
		return (int) get_user_meta($user_id, $key, true);
	}

	public static function set_open_folder_id(int $user_id, int $folder_id, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$key = $post_type ? self::OPEN_FOLDER_META . '_' . sanitize_key($post_type) : self::OPEN_FOLDER_META;
		$key .= self::blog_suffix();
		update_user_meta($user_id, $key, absint($folder_id));
	}

	/** @return array<int, int> */
	public static function get_favorites(int $user_id, string $post_type = ''): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
		$key .= self::blog_suffix();
		$raw = get_user_meta($user_id, $key, true);
		return is_array($raw) ? array_values(array_map('intval', $raw)) : [];
	}

	/**
	 * @param array<array-key, mixed> $ids
	 */
	public static function set_favorites(int $user_id, array $ids, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// [internal] ([internal]): best-effort serialization против merge_favorites()
		// того же (user_id, post_type) — не gate. GET_LOCK недоступность (NULL, managed
		// MySQL без advisory-локов) деградирует к записи без лока, как и раньше этого
		// пакета: контракт set_favorites(): void не меняется, отказа/исключения нет.
		$lock_name = self::favorites_lock_name($user_id, $post_type);
		$acquired  = DbAdvisoryLock::acquire($lock_name, 3);

		try {
			$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
			$key .= self::blog_suffix();
			update_user_meta($user_id, $key, array_values(array_map('absint', $ids)));
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release($lock_name);
			}
		}

		// Нейтральное доменное событие «фавориты пользователя изменились» ([internal]). Это ЕДИНСТВЕННАЯ
		// точка записи фаворита — событие покрывает все пути (REST FavoritesController, PresetApplyPipeline,
		// будущие) разом. Владелец кэша (Dashboard) подписывается и инвалидирует dashboard_favorites_stats.
		// Публикация хука ≠ зависимость User→Dashboard: убрать Dashboard → событие без подписчика, цело.
		do_action('plathix/favorites/changed', $user_id, $post_type);
	}

	/**
	 * Атомарный read-merge-write для избранного: добавляет $to_add к уже сохранённому
	 * списку под тем же локом, что set_favorites() ([internal], [internal]). Раньше
	 * потребитель (PresetApplyPipeline) делал get_favorites()->merge->set_favorites()
	 * вручную, без сериализации против конкурентного replace-вызова set_favorites() —
	 * REST-writer, пишущий между чтением и записью этой пары, терял свою запись
	 * (устаревшее прочитанное состояние молча перезаписывало его). Оба метода делят
	 * favorites_lock_name(), поэтому конкурентный set_favorites() либо ждёт (timeout=3с,
	 * короткая критическая секция — один update_user_meta), либо (GET_LOCK недоступен)
	 * оба метода одинаково деградируют к best-effort без лока.
	 *
	 * Не вызывает set_favorites() изнутри — GET_LOCK не реентерабелен в рамках одной
	 * MySQL-сессии, повторный acquire того же имени в той же сессии вернул бы отказ.
	 *
	 * @param array<array-key, mixed> $to_add
	 */
	public static function merge_favorites(int $user_id, array $to_add, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$lock_name = self::favorites_lock_name($user_id, $post_type);
		$acquired  = DbAdvisoryLock::acquire($lock_name, 3);

		try {
			$existing = self::get_favorites($user_id, $post_type);
			$merged   = array_values(array_unique(array_merge(
				$existing,
				array_map('absint', $to_add)
			)));

			$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
			$key .= self::blog_suffix();
			update_user_meta($user_id, $key, $merged);
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release($lock_name);
			}
		}

		do_action('plathix/favorites/changed', $user_id, $post_type);
	}

	private static function favorites_lock_name(int $user_id, string $post_type): string {
		return Keys::lock('favorites_' . $user_id . '_' . ($post_type ?: 'default'));
	}
}
