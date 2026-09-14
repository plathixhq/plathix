<?php

declare(strict_types=1);

namespace Plathix\Core;

final class MbCompat
{
	public static function strtolower(string $value): string {
		return \function_exists('mb_strtolower') ? \mb_strtolower($value) : \strtolower($value);
	}

	public static function substr(string $value, int $start, ?int $length = null): string {
		return \function_exists('mb_substr')
			? \mb_substr($value, $start, $length)
			: \substr($value, $start, $length ?? \PHP_INT_MAX);
	}

	public static function strlen(string $value): int {
		return \function_exists('mb_strlen') ? \mb_strlen($value) : \strlen($value);
	}
}
