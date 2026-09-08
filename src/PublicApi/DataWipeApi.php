<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\DataWipe\DangerZoneTab;

final class DataWipeApi
{

	public function tabSlug(): string
	{
		return DangerZoneTab::TAB;
	}
}
