<?php

declare(strict_types=1);

namespace Plathix\Modules\Tools;

use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		if ( is_admin() ) {
			( new ToolsPage() )->register();
		}
	}
}
