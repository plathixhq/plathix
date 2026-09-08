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

		public readonly ?int $foldersCount = null,

		public readonly ?int $countRecursive = null
	) {
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return get_object_vars($this);
	}
}
