<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		( new AttachmentReplaceUi() )->register();
	}
}
