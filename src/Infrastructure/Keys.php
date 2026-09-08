<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Keys
{
	private const MAX_TRANSIENT_KEY_LEN = 150;

	public static function transient(string $name, int $blog_id = 0): string {
		$blog_id = $blog_id ?: get_current_blog_id();
		self::assertName($name);

		$key = "plathix_{$blog_id}_{$name}";

		if ( strlen($key) <= self::MAX_TRANSIENT_KEY_LEN ) {
			return $key;
		}

		return "plathix_{$blog_id}_h_" . md5($name);
	}

	public static function lock(string $name, int $blog_id = 0): string {
		$blog_id = $blog_id ?: get_current_blog_id();
		self::assertName($name);

		return "{$blog_id}_{$name}";
	}

	public static function jobResult(int $action_id): string {
		return "plathix_job_result_{$action_id}";
	}

	public static function download(int $action_id): string {
		return "plathix_dl_job_{$action_id}";
	}

	public static function licenseError(): string {
		return 'plathix_license_last_error';
	}

	public static function blogSuffix(): string {
		return is_multisite() ? '_' . get_current_blog_id() : '';
	}

	private static function assertName(string $name): void {
		if ( defined('WP_DEBUG') && WP_DEBUG && preg_match('/^plathix_\d+_/', $name) ) {
			throw new \LogicException("Keys: name '{$name}' already contains blog_id prefix."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException behind WP_DEBUG; $name is a key literal from plugin code, not user input
		}
	}
}
