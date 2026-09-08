<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

class WickedFolders extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'wf_attachment_folders';

	public function key(): string {
		return 'wickedfolders';
	}
}
