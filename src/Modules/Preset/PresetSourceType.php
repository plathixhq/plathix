<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetSourceType
{
	public const BUILTIN   = 'builtin';
	public const CUSTOM    = 'custom';
	public const COMMUNITY = 'community';
	public const EXPORTED  = 'exported';

	/** @return string[] */
	public static function all(): array {
		return [
			self::BUILTIN,
			self::CUSTOM,
			self::COMMUNITY,
			self::EXPORTED,
		];
	}

	public static function is_valid(string $value): bool {
		return in_array($value, self::all(), true);
	}

	public static function normalize(string $value): string {
		$value = sanitize_key($value);

		return self::is_valid($value) ? $value : self::CUSTOM;
	}
}
