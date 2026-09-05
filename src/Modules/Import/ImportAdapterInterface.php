<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

interface ImportAdapterInterface
{
	public function key(): string;

	public function is_available(): bool;

	/**
	 * @return array<int, array{id:int,name:string,parent:int,items:int[]}>
	 */
	public function export_tree(): array;

	/**
	 * [internal]: true если ПОСЛЕДНИЙ вызов export_tree() вернул [] из-за реальной
	 * SQL-ошибки, а не потому что источник действительно пуст — вызывающая сторона
	 * не должна трактовать это как "нечего было переносить" (ImportManager::handle_job_import()).
	 */
	public function had_query_failure(): bool;
}
