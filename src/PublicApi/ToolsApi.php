<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Tools\ToolsPage;

final class ToolsApi
{

	public function pageUrl(): string
	{
		return admin_url( 'admin.php?page=' . ToolsPage::PAGE_SLUG );
	}

	public function pageSlug(): string
	{
		return ToolsPage::PAGE_SLUG;
	}
}
