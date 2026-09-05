<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

/**
 * Импорт папок FileBird. Вся механика чтения чужой таксономии — в
 * {@see AbstractTaxonomyImportAdapter}; здесь только то, чем этот конкурент отличается.
 */
class FileBird extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'njt_fbv';

	public function key(): string {
		return 'filebird';
	}
}
