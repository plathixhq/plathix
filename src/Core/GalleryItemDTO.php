<?php

declare(strict_types=1);

namespace Plathix\Core;

final class GalleryItemDTO
{
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $url,
		public readonly string $thumbnail_url,
		public readonly string $mime_type,
		public readonly string $alt,
		public readonly string $caption,
		public readonly int $width,
		public readonly int $height,
		public readonly bool $is_video,
		public readonly bool $is_image,
		public readonly string $badge_type,
		public readonly string $type_label,
		public readonly int $file_size_bytes,
		public readonly string $file_size_human
	) {
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return get_object_vars($this);
	}
}
