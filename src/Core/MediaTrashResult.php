<?php

declare(strict_types=1);

namespace Plathix\Core;

final class MediaTrashResult
{
	public function __construct(
		/** @var int[] */
		public readonly array $trashed,
		/** @var int[] */
		public readonly array $failed,
		/** @var int[] */
		public readonly array $skipped
	) {
	}

	/** @return array{trashed: array<int>, failed: array<int>, skipped: array<int>} */
	public function toArray(): array {
		return [
			'trashed' => $this->trashed,
			'failed'  => $this->failed,
			'skipped' => $this->skipped,
		];
	}
}
