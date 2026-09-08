<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Modules\Trash\FolderRestoreService;
use Plathix\Modules\Trash\FolderTrashService;

final class FolderTrashRunner
{
	public static function trash(int $id, string $taxonomy, string $on_children = FolderTreeService::DEFAULT_ON_CHILDREN): bool
	{
		return (new FolderTrashService())->trash($id, $taxonomy, $on_children);
	}

	/**
	 * @return array{restored:bool, fallbackRoot:bool, parent:int}
	 */
	public static function restore(int $id, string $taxonomy): array
	{
		return (new FolderRestoreService())->restore($id, $taxonomy);
	}
}
