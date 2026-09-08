<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

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
			( new PresetsPage() )->register();

			( new PresetPostActions() )->register();

		}
	}
}
