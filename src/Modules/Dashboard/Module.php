<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard;

use Plathix\Contracts\ModuleInterface;
use Plathix\Modules\Dashboard\Stats\UserFavoritesService;
use Plathix\Modules\Dashboard\Widgets\OnboardingWidget;

class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{

		add_action( 'plathix/favorites/changed', [ UserFavoritesService::class, 'invalidate' ], 10, 2 );

		if ( is_admin() ) {
			( new HomeDashboardPage() )->register();

			add_action( 'plathix/dashboard/renderOnboarding', [ $this, 'renderOnboarding' ] );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */

	public function renderOnboarding(array $data): void
	{
		( new OnboardingWidget() )->render( $data );
	}
}
