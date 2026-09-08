<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\Features;

final class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		if ( ! Features::isEnabled( 'import' ) ) {
			return;
		}

		$manager = new ImportManager();
		$manager->register();

		( new ImportAjaxHandler() )->register();

		if ( is_admin() ) {
			( new ImportToolsCard( $manager ) )->register();
			( new ImportEnqueueService() )->register();
		}
	}
}
