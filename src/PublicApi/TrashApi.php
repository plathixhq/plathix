<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Trash\Module;

final class TrashApi
{

	public function trashTimeMetaKey(): string
	{
		return Module::TRASH_TIME_META;
	}
}
