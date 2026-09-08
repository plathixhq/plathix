<?php

declare(strict_types=1);

namespace Plathix\Core;

final class MediaTrashRunner
{
	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public static function trash(array $ids, string $taxonomy): array
	{
		return (new MediaDeleteService())->bulkTrash($ids, $taxonomy)->toArray();
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public static function restore(array $ids, int $targetFolderId, string $taxonomy): array
	{
		return (new MediaDeleteService())->bulkRestore($ids, $targetFolderId, $taxonomy)->toArray();
	}
}
