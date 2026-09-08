<?php

declare(strict_types=1);

namespace Plathix\User;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Keys;
use Plathix\Infrastructure\Logger;

class Preferences
{
	private const OPEN_FOLDER_META = 'plathix_open_folder_id';
	public const FAVORITES_META    = 'plathix_favorites';

	public static function blogSuffix(): string {
		return Keys::blogSuffix();
	}

	public static function getOpenFolderId(int $user_id, string $post_type = ''): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$key = $post_type ? self::OPEN_FOLDER_META . '_' . sanitize_key($post_type) : self::OPEN_FOLDER_META;
		$key .= self::blogSuffix();
		return (int) get_user_meta($user_id, $key, true);
	}

	public static function setOpenFolderId(int $user_id, int $folder_id, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$key = $post_type ? self::OPEN_FOLDER_META . '_' . sanitize_key($post_type) : self::OPEN_FOLDER_META;
		$key .= self::blogSuffix();
		update_user_meta($user_id, $key, absint($folder_id));
	}

	/** @return array<int, int> */
	public static function getFavorites(int $user_id, string $post_type = ''): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
		$key .= self::blogSuffix();
		$raw = get_user_meta($user_id, $key, true);
		return is_array($raw) ? array_values(array_map('intval', $raw)) : [];
	}

	/**
	 * @param array<array-key, mixed> $ids
	 */
	public static function setFavorites(int $user_id, array $ids, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$lock_name = self::favoritesLockName($user_id, $post_type);
		$acquired  = DbAdvisoryLock::acquire($lock_name, 3);

		try {
			$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
			$key .= self::blogSuffix();
			$new_ids = array_values(array_map('absint', $ids));

			$old_raw = get_user_meta($user_id, $key, true);
			$old_ids = is_array($old_raw) ? array_values(array_map('intval', $old_raw)) : [];
			$written = update_user_meta($user_id, $key, $new_ids);

			if ( ! $written && $old_ids !== $new_ids ) {
				Logger::error('favorites_meta_write_failed', [ 'user_id' => $user_id ]);
			}
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release($lock_name);
			}
		}

		do_action('plathix/favorites/changed', $user_id, $post_type);
	}

	/**
	 * @param array<array-key, mixed> $to_add
	 */

	public static function mergeFavorites(int $user_id, array $to_add, string $post_type = ''): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$lock_name = self::favoritesLockName($user_id, $post_type);
		$acquired  = DbAdvisoryLock::acquire($lock_name, 3);

		try {
			$existing = self::getFavorites($user_id, $post_type);
			$merged   = array_values(array_unique(array_merge(
				$existing,
				array_map('absint', $to_add)
			)));

			$key = $post_type ? self::FAVORITES_META . '_' . sanitize_key($post_type) : self::FAVORITES_META;
			$key .= self::blogSuffix();
			update_user_meta($user_id, $key, $merged);
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release($lock_name);
			}
		}

		do_action('plathix/favorites/changed', $user_id, $post_type);
	}

	private static function favoritesLockName(int $user_id, string $post_type): string {
		return Keys::lock('favorites_' . $user_id . '_' . ($post_type ?: 'default'));
	}
}
