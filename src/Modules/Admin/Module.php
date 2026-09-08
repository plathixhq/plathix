<?php

declare(strict_types=1);

namespace Plathix\Modules\Admin;

use Plathix\Admin\AdminMenuManager;
use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{
	public function register(): void
	{
		( new AdminMenuManager() )->register();

	}
}
