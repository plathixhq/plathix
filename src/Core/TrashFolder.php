<?php

declare(strict_types=1);

namespace Plathix\Core;

final class TrashFolder
{

	public static function id(string $taxonomy): int {
		return (int) apply_filters( 'plathix/folder/trash_id', 0, $taxonomy );
	}
}
