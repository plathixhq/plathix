<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Modules\Trash\FolderRestoreService;
use Plathix\Modules\Trash\FolderTrashService;

/**
 * Платформенный дефолт-резолвер мягкого удаления папки ([internal]).
 *
 * Аналог MediaTrashRunner для папок: держит ЕДИНСТВЕННУЮ во Free прямую связь дефолта с
 * Modules\Trash\FolderTrashService, чтобы платформенный FolderTreeService её НЕ держал напрямую
 * (иначе reverse-coupling платформа→модуль). FolderTreeService зовёт callable через слот
 * `plathix/folder/trash_runner`; без подписчика — этот дефолт.
 *
 * Модуль Trash может переопределить слот своим runner'ом; тогда этот дефолт становится тонким
 * мостом (или уезжает вместе с классом при выносе модуля в PRO).
 */
final class FolderTrashRunner
{
	public static function trash(int $id, string $taxonomy, string $on_children = 'delete'): bool
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
