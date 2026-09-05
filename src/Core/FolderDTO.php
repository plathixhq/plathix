<?php

declare(strict_types=1);

namespace Plathix\Core;

final class FolderDTO
{
	public function __construct(
		public readonly int|string $id,
		public readonly string $name,
		public readonly int $parentId,
		public readonly int $position,
		public readonly string $color,
		public readonly string $icon,
		public readonly ?int $count,
		public readonly string $taxonomy,
		public readonly bool $isProtected = false,
		public readonly bool $hasChildren = false,
		// Число soft-trashed папок. Заполняется только у Trash-DTO ([internal]);
		// у остальных папок null. Узел Trash показывает «N Ф / N П» — файлы (count) и папки
		// (folders_count) раздельно; обычная папка — только свой count.
		public readonly ?int $foldersCount = null,
		// ВНЕШНИЙ ПОТРЕБИТЕЛЬ ([internal], [internal]): PRO-виджет FolderInfo читает
		// это поле прямо из стора сайдбара вместо отдельного REST-запроса за числом.
		// Поле не «просто вычисляется» — удаление его из payload сломает PRO молча.
		// [internal] MSC-104: файлов в папке + всех подпапках (FolderCountService::
		// get_recursive_count(), termmeta point-update — не пересчитывается на каждое
		// чтение). null у специальных узлов (All Files/Uncategorized/Trash) — у них нет
		// однозначного «поддерева» в том же смысле, что у обычной пользовательской
		// папки (та же семантика null, что уже применяет foldersCount выше).
		public readonly ?int $countRecursive = null
	) {
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return get_object_vars($this);
	}
}
