<?php

declare(strict_types=1);

namespace Plathix\Core;

final class ImportJobDTO
{
	public function __construct(

		public readonly string $status,

		public readonly int $jobId,
		public readonly string $adapter,
		public readonly string $postType
	) {
	}

	public function isQueued(): bool {
		return 'queued' === $this->status;
	}
}
