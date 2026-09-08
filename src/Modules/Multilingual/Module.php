<?php

declare(strict_types=1);

namespace Plathix\Modules\Multilingual;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Logger;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

final class Module implements ModuleInterface
{
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );
	}

	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rateLimiter = null, ?Loader $loader = null): void
	{
		if ( $loader === null ) {
			Logger::error( __METHOD__ . ': Multilingual module requires a Loader instance.' );
			return;
		}

		new MultilingualIntegration( $loader );
	}
}
