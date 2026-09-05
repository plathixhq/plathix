<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetError
{
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly ?int $line = null,
		public readonly ?string $section = null,
		public readonly bool $fatal = true
	) {
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return [
			'code' => $this->code,
			'message' => $this->message,
			'line' => $this->line,
			'section' => $this->section,
			'fatal' => $this->fatal,
		];
	}
}
