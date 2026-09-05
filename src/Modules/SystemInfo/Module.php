<?php

declare(strict_types=1);

namespace Plathix\Modules\SystemInfo;

use Plathix\Contracts\ModuleInterface;

/**
 * SystemInfo module — admin-страница диагностики (версии, статус БД, ActionScheduler-джобы,
 * SVG-sanitizer, health). Регистрируется из plathix.php под `plathix/modules/register`;
 * ранее бутстрапилась монолитом Modules\Admin\Module.
 *
 * Двухфазный bootstrap (module-standard.md свойство 3): register() в фазе 1 ТОЛЬКО
 * подписывается на `plathix/modules/boot`; runtime-хук WP (`admin_menu` через SystemInfoPage)
 * навешивается в boot() под is_admin() — фаза 1 запрещает вешать runtime-хуки WP (:109).
 * DI не нужен — P4 у SystemInfoPage tolerated (read-only диагностика, прямого new чужой инфры нет).
 *
 * SystemInfoPage перенесён в Plathix\Modules\SystemInfo ([internal]).
 */
class Module implements ModuleInterface
{
	/** Фаза 1: только подписка на фазу 2. */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/** Фаза 2: создание страницы (admin_menu вешается здесь). */
	public function boot(): void
	{
		if ( is_admin() ) {
			( new SystemInfoPage() )->register();
		}
	}
}
