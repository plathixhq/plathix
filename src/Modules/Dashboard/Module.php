<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard;

use Plathix\Contracts\ModuleInterface;
use Plathix\Modules\Dashboard\Stats\UserFavoritesService;
use Plathix\Modules\Dashboard\Widgets\OnboardingWidget;

class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только подписка на фазу 2. Dashboard не использует платформенные сервисы хука
	 * (jobs/rate_limiter/loader), поэтому boot() — без аргументов и без $accepted_args.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: регистрация admin-страницы дашборда, когда все модули прошли register.
	 */
	public function boot(): void
	{
		// Инвалидация favorites-кэша дашборда ([internal]) — ВНЕ is_admin(): запись фаворита (REST)
		// может идти в любом контексте, кэш dashboard_favorites_stats общий → чистим всегда. Образец
		// событийной инвалидации — Cache::on_attachment_change (Plugin.php). Владелец кэша (Dashboard)
		// подписывается на нейтральное доменное событие; знание ключа инкапсулировано в UserFavoritesService.
		add_action( 'plathix/favorites/changed', [ UserFavoritesService::class, 'invalidate' ], 10, 2 );

		if ( is_admin() ) {
			( new HomeDashboardPage() )->register();
			// Setup-блок «Finish setup» рендерится в сетке дашборда по собственной точке
			// расширения. Инверсия сохранена: Dashboard публикует хук в HomeDashboardPage
			// (do_action) И подписывается на него своим рендерером ([internal] — виджет
			// переехал из Modules\Onboarding, cross-package override [internal]).
			add_action( 'plathix/dashboard/render_onboarding', [ $this, 'render_onboarding' ] );
		}
	}

	/**
	 * Подписчик собственной точки расширения дашборда: рендерит setup-блок «Finish setup».
	 *
	 * @param array<string, mixed> $data Данные дашборда (onboarding_cards, show_onboarding),
	 *                                   собираются HomeDashboardData.
	 */
	public function render_onboarding(array $data): void
	{
		( new OnboardingWidget() )->render( $data );
	}
}
