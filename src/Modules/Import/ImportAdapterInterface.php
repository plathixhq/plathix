<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

interface ImportAdapterInterface
{
	public function key(): string;

	public function isAvailable(): bool;

	/**
	 * @return array<int, array{id:int,name:string,parent:int,items:int[]}>
	 */
	public function exportTree(): array;

	public function hadQueryFailure(): bool;
}
