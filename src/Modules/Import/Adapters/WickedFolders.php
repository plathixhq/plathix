<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

/**
 * Импорт папок WickedFolders. Вся механика чтения чужой таксономии — в
 * {@see AbstractTaxonomyImportAdapter}; здесь только то, чем этот конкурент отличается.
 */
class WickedFolders extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'wf_attachment_folders';

	public function key(): string {
		return 'wickedfolders';
	}
}
