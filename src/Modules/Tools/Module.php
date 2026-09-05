<?php

declare(strict_types=1);

namespace Plathix\Modules\Tools;

use Plathix\Contracts\ModuleInterface;

/**
 * Tools module — admin-страница «редких операций» (Free: импорт структуры, экспорт preset-ZIP).
 * Регистрируется из plathix.php под `plathix/modules/register`; ранее страница бутстрапилась
 * монолитом Modules\Admin\Module.
 *
 * Двухфазный bootstrap (module-standard.md свойство 3): register() в фазе 1 ТОЛЬКО
 * подписывается на `plathix/modules/boot`; runtime-хук WP (`admin_menu` через ToolsPage)
 * навешивается в boot() под is_admin() — фаза 1 запрещает вешать runtime-хуки WP (:109).
 * [internal]: карточка импорта перенесена в Modules\Import\ImportToolsCard, подписывается
 * на слот `plathix/tools/cards` prio 10. ToolsPage больше не зависит от ImportManager.
 *
 * [internal]: PRO-карточка выдачи REST-токенов вырезана из ToolsPage в модуль
 * ApiKey — Module больше не создаёт ServiceTokenRepository (Free не зависит от PRO-repo). Карточка
 * приходит на страницу через слот `do_action('plathix/tools/cards')`, на который подписан ApiKey.
 *
 * ToolsPage перенесён в Plathix\Modules\Tools ([internal]).
 */
class Module implements ModuleInterface
{
	/** Фаза 1: только подписка на фазу 2. */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/** Фаза 2: создание и регистрация страницы (admin_menu навешивается здесь). */
	public function boot(): void
	{
		if ( is_admin() ) {
			( new ToolsPage() )->register();
		}
	}
}
