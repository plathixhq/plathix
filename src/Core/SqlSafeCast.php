<?php

declare(strict_types=1);

namespace Plathix\Core;

final class SqlSafeCast
{
	/**
	 * @param mixed $raw
	 * @return int|null
	 */

	public static function nullSafeSqlCount(mixed $raw): ?int
	{
		if ( null === $raw ) {
			return null;
		}

		return max( 0, (int) $raw );
	}

	/**
	 * @param array<int, mixed>|null $raw
	 * @return array<int, mixed>|null
	 */

	public static function nullSafeSqlRows(?array $raw): ?array
	{
		return $raw;
	}
}
