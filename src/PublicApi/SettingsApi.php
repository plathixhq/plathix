<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Settings\SettingsPage;

final class SettingsApi
{

	public function pageUrl(?string $tab = null): string
	{
		$url = admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG );

		return null === $tab ? $url : $url . '&tab=' . $tab;
	}

	public function pageSlug(): string
	{
		return SettingsPage::PAGE_SLUG;
	}
}
