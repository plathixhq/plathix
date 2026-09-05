<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

/**
 * Тонкая модульная обёртка страницы настроек плагина.
 *
 * `SettingsPage` исторически бутстрапился напрямую в `Plugin::boot()`
 * (`new SettingsPage(loader: $this->loader)`). Эта обёртка переводит создание на
 * стандартный двухфазный bootstrap без физического переноса классов (namespace
 * `Plathix\Admin` — legacy tolerated, мигрирует единым витком [internal]).
 *
 * Loader приходит аргументом хука `plathix/modules/boot` (Опция A — общий
 * платформенный экземпляр, тот же что у Upload/ApiKey).
 */
final class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только подписка на фазу 2. Runtime-хуки WP здесь не вешаются.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );
	}

	/**
	 * Фаза 2: создаёт SettingsPage из платформенного Loader, пришедшего аргументом хука.
	 *
	 * SettingsPage в конструкторе навешивает admin_menu, admin_init и прочие хуки через Loader.
	 * boot стреляет до `Loader::run()`, поэтому хуки успевают зарегистрироваться.
	 *
	 * @param JobDispatcher|null $jobs         Не используется Settings (контракт хука).
	 * @param RateLimiter|null   $rate_limiter Не используется Settings (контракт хука).
	 * @param Loader|null        $loader       Платформенный Loader (вешает admin-хуки).
	 */
	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rate_limiter = null, ?Loader $loader = null): void
	{
		if ( $loader === null ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Диагностика нарушения контракта хука (loader обязателен) — только при WP_DEBUG, не шумит в прод-лог.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug log gated behind WP_DEBUG, not shipped debug code
				error_log( __METHOD__ . ': Settings module requires a Loader instance.' );
			}
			return;
		}
		new SettingsPage( loader: $loader );
	}
}
