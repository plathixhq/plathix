<?php

declare(strict_types=1);

namespace Plathix\Http;

final class RestFolderResponseBuilders
{
	/**
	 * @param array<string, mixed> $result
	 * @return array{items: list<mixed>, total: int, page: int, perPage: int, taxonomy: string, folderId: int}
	 */
	public static function folder_items(array $result, string $taxonomy, int $folder_id, int $page, int $per_page): array {
		return [
			'items'    => array_values( (array) ( $result['items'] ?? [] ) ),
			'total'    => (int) ( $result['total'] ?? 0 ),
			'page'     => (int) ( $result['page'] ?? $page ),
			'perPage'  => (int) ( $result['per_page'] ?? $per_page ),
			'taxonomy' => $taxonomy,
			'folderId' => $folder_id,
		];
	}

	/**
	 * @return array{bytes:int,bytesChildren:int}
	 */
	public static function folder_size(int $bytes_own, int $bytes_children): array {
		return [
			'bytes'         => $bytes_own,
			'bytesChildren' => $bytes_children,
		];
	}

	public static function folder_size_cache_key(int $folder_id, string $taxonomy): string {
		return "folder_size_{$folder_id}_{$taxonomy}";
	}
}
