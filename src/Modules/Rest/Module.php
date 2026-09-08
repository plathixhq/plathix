<?php

declare(strict_types=1);

namespace Plathix\Modules\Rest;

use Plathix\Contracts\ModuleInterface;
use Plathix\Http\ReplaceRoutes;
use Plathix\Http\RestController;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

final class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );
	}

	/**
	 * @param JobDispatcher|null $jobs
	 * @param RateLimiter|null   $rateLimiter
	 * @param Loader|null        $loader
	 */

	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rateLimiter = null, ?Loader $loader = null): void
	{
		( new ReplaceRoutes() )->register();


		if ( $jobs !== null && $rateLimiter !== null && $loader !== null ) {

			new RestController( $loader, $rateLimiter );
		}
	}
}
