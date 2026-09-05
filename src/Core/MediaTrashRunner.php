<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Платформенный дефолт-резолвер действий корзины ([internal]).
 *
 * Держит ЕДИНСТВЕННУЮ во Free прямую связь дефолта с MediaDeleteService, чтобы публичный
 * фасад AssignmentsApi её НЕ держал (иначе перенос MediaDeleteService в Modules\Trash создал бы
 * reverse-coupling платформа→модуль, ось B2 module-standard). Фасад зовёт эти callable через
 * слоты `plathix/media/trash_runner` / `plathix/media/restore_runner`; без подписчика — этот дефолт.
 *
 * При выносе действий в Modules\Trash модуль переопределяет слоты своим runner'ом, а этот
 * платформенный дефолт становится тонким мостом (или уезжает вместе с классом).
 */
final class MediaTrashRunner
{
	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public static function trash(array $ids, string $taxonomy): array
	{
		return (new MediaDeleteService())->bulk_trash($ids, $taxonomy)->toArray();
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public static function restore(array $ids, int $targetFolderId, string $taxonomy): array
	{
		return (new MediaDeleteService())->bulk_restore($ids, $targetFolderId, $taxonomy)->toArray();
	}
}
