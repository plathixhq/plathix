<?php

declare(strict_types=1);

namespace Plathix\Modules\Rest;

use Plathix\Contracts\ModuleInterface;
use Plathix\Http\ReplaceRoutes;
use Plathix\Http\RestController;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

/**
 * Тонкая модульная обёртка REST-фичи.
 *
 * REST-route-классы (`Plathix\Http\*`) исторически бутстрапились напрямую в
 * `Plugin::boot()`. Эта обёртка переводит САМОДОСТАТОЧНЫЕ route-классы
 * (`ReplaceRoutes` — без конструктор-зависимостей) на стандартный двухфазный bootstrap
 * без физического переноса классов и смены namespace (`Plathix\Http` — legacy tolerated,
 * мигрирует по касанию).
 *
 * `RestController` (routing-фасад после phase2-сплита) инстанцируется этим модулем в фазе 2:
 * платформенные сервисы $jobs/$rate_limiter/$loader приходят аргументами хука
 * `plathix/modules/boot` (штатная инъекция платформы в модуль — свойство 4; target #2,
 * Опция A, см. rest-module-audit §7). $jobs/$rate_limiter — ОБЩИЕ экземпляры (те же, что
 * у AjaxRouter), не пересоздаём.
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
	 * Фаза 2: регистрирует самодостаточные REST-route-классы и инстанцирует RestController.
	 *
	 * Каждый route-класс в своём `register()` подписывается на `rest_api_init`; boot стреляет
	 * до `rest_api_init`, поэтому маршруты успевают зарегистрироваться. БЕЗ гейта флага (REST
	 * всегда активен, как было в Plugin::boot — отключаемость отдельным осознанным шагом).
	 *
	 * Платформенные сервисы для RestController приходят аргументами хука `plathix/modules/boot`
	 * (Plugin передаёт уже созданные $jobs/$rate_limiter/$loader, после register_handlers()).
	 * Дефолты null + guard: если модуль вызван без сервисов (вне Plugin-контекста), RestController
	 * не создаётся — 3 route-класса работают как прежде, без фатала.
	 *
	 * @param JobDispatcher|null $jobs         Общий диспетчер задач (тот же, что у AjaxRouter).
	 * @param RateLimiter|null   $rate_limiter Общий rate-limiter.
	 * @param Loader|null        $loader       Платформенный Loader (вешает rest_api_init).
	 */
	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rate_limiter = null, ?Loader $loader = null): void
	{
		( new ReplaceRoutes() )->register();
		// ShortcodeEditRoutes (/gallery/shortcode-usage) переехал в PRO вместе с модулем
		// Gallery ([internal]) — Free его больше не регистрирует. Без PRO роута нет.
		// MediaGridEndpoint (/media-grid) удалён как мёртвый REST-канал
		// ([internal]): клиент patchMediaGridSync не вызывался, медиатека
		// работает через родной ajax_query_attachments. Удалён вместе с route-классом.

		if ( $jobs !== null && $rate_limiter !== null && $loader !== null ) {
			// RestController ctor сам вешает rest_api_init через $loader->add_action.
			// [internal] ([internal]): $jobs больше не передаётся — его единственным
			// потребителем внутри RestController был удалённый ImportRequestHandler.
			new RestController( $loader, $rate_limiter );
		}
	}
}
