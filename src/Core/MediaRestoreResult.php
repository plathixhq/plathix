<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Результат {@see MediaDeleteService::bulk_restore()} ([internal], [internal],
 * хвост #512/#452). См. {@see MediaTrashResult} для полного обоснования.
 */
final class MediaRestoreResult
{
	public function __construct(
		/** @var int[] */
		public readonly array $restored,
		/** @var int[] */
		public readonly array $failed,
		/** @var int[] */
		public readonly array $skipped
	) {
	}

	/** @return array{restored: array<int>, failed: array<int>, skipped: array<int>} */
	public function toArray(): array {
		return [
			'restored' => $this->restored,
			'failed'   => $this->failed,
			'skipped'  => $this->skipped,
		];
	}
}
