<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Keys
{
	private const MAX_TRANSIENT_KEY_LEN = 150;

	public static function transient(string $name, int $blog_id = 0): string {
		$blog_id = $blog_id ?: get_current_blog_id();
		self::assert_name($name);

		$key = "plathix_{$blog_id}_{$name}";

		if ( strlen($key) <= self::MAX_TRANSIENT_KEY_LEN ) {
			return $key;
		}

		return "plathix_{$blog_id}_h_" . md5($name);
	}

	public static function lock(string $name, int $blog_id = 0): string {
		$blog_id = $blog_id ?: get_current_blog_id();
		self::assert_name($name);

		return "{$blog_id}_{$name}";
	}

	public static function job_result(int $action_id): string {
		return "plathix_job_result_{$action_id}";
	}

	public static function download(int $action_id): string {
		return "plathix_dl_job_{$action_id}";
	}

	/**
	 * Ключ транзиента «код последней ошибки лицензии» — межплагинный контракт Free↔PRO.
	 * PRO ПИШЕТ этот транзиент (PlathixPro\Modules\License\Module::ERROR_TRANSIENT), Free
	 * только читает/удаляет. Ключ БУКВАЛЬНЫЙ, site-wide (без blog_id-префикса) — менять
	 * значение нельзя, иначе рассинхрон с PRO. Метод существует, чтобы Free-потребители
	 * шли через Keys (check-keys.sh), не хардкодя строку.
	 */
	public static function license_error(): string {
		return 'plathix_license_last_error';
	}

	/**
	 * Blog-scoped суффикс для user_meta-ключей ([internal]/#649): условный (только
	 * is_multisite()) `_<blog_id>` в конце имени. Единственный владелец этой формулы на
	 * Free-стороне — Preferences::blog_suffix() и HomeDashboardPage::blog_scoped_meta_key()
	 * делегируют сюда вместо собственной копии тела. НЕ инфикс-формат (см. prefix() в
	 * Cache) — разные позиционные семантики намеренно живут в разных методах, не
	 * смешиваются.
	 */
	public static function blog_suffix(): string {
		return is_multisite() ? '_' . get_current_blog_id() : '';
	}

	private static function assert_name(string $name): void {
		if ( defined('WP_DEBUG') && WP_DEBUG && preg_match('/^plathix_\d+_/', $name) ) {
			throw new \LogicException("Keys: name '{$name}' already contains blog_id prefix."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException behind WP_DEBUG; $name is a key literal from plugin code, not user input
		}
	}
}
