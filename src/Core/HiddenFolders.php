<?php

declare(strict_types=1);

namespace Plathix\Core;

final class HiddenFolders
{
	/**
	 * @return array<int, int>
	 */

	public static function ids(string $taxonomy): array {
		/** @var array<int, int> $ids */
		$ids = (array) apply_filters( 'plathix/folder/hidden_ids', [], $taxonomy );

		return $ids;
	}
}
