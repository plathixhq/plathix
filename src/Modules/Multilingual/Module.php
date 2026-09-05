<?php

declare(strict_types=1);

namespace Plathix\Modules\Multilingual;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

/**
 * Тонкая модульная обёртка мультиязычной интеграции (WPML/Polylang).
 *
 * Переводит MultilingualIntegration на двухфазный bootstrap.
 * Loader приходит третьим аргументом хука plathix/modules/boot — тот же паттерн
 * что у Modules\Settings\Module.
 */
final class Module implements ModuleInterface
{
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );
	}

	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rate_limiter = null, ?Loader $loader = null): void
	{
		if ( $loader === null ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Диагностика нарушения контракта хука (loader обязателен) — только при WP_DEBUG, не шумит в прод-лог.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug log gated behind WP_DEBUG, not shipped debug code
				error_log( __METHOD__ . ': Multilingual module requires a Loader instance.' );
			}
			return;
		}

		new MultilingualIntegration( $loader );
	}
}
