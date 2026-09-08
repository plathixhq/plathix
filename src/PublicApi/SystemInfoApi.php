<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\SystemInfo\SystemInfoPage;

final class SystemInfoApi
{

	public function pageUrl(): string
	{
		return admin_url( 'admin.php?page=' . SystemInfoPage::PAGE_SLUG );
	}
}
