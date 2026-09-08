<?php

declare(strict_types=1);

namespace Plathix\Core;

final class FolderTreeBootstrapStrategy
{
	public static function threshold(): int {
		return (int) apply_filters( 'plathix/sidebar/lazy_tree_threshold', 200 );
	}

	public static function shouldDefer(int $folder_count): bool {
		return $folder_count > self::threshold();
	}
}
