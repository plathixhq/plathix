<?php

declare(strict_types=1);

namespace Plathix\Core;

final class MediaMoveResult
{
	public function __construct(
		public readonly int $moved,
		public readonly int $skipped,
		/** @var int[] */
		public readonly array $failed,
		public readonly int $folder_id,
		public readonly string $taxonomy,
		/** @var array<mixed> */
		public readonly array $counts_recomputed,
		/** @var array<int,int> */
		public readonly array $counts,
		/** @var int[] */
		public readonly array $restored,
		/** @var int[] */
		public readonly array $trashed
	) {
	}

	/**
	 * @return array{moved: int, skipped: int, failed: array<int>, folder_id: int,
	 *     taxonomy: string, counts_recomputed: array<mixed>, counts: array<int,int>,
	 *     restored: array<int>, trashed: array<int>}
	 */
	public function toArray(): array {
		return [
			'moved'             => $this->moved,
			'skipped'           => $this->skipped,
			'failed'            => $this->failed,
			'folder_id'         => $this->folder_id,
			'taxonomy'          => $this->taxonomy,
			'counts_recomputed' => $this->counts_recomputed,
			'counts'            => $this->counts,
			'restored'          => $this->restored,
			'trashed'           => $this->trashed,
		];
	}
}
