<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\Contracts\ModuleInterface;

/**
 * Pro module — admin-страница PRO (маркетинг + форма лицензионного ключа).
 * Регистрируется из plathix.php под `plathix/modules/register`; ранее бутстрапилась
 * монолитом Modules\Admin\Module.
 *
 * Двухфазный bootstrap (паттерн парка): register() только подписывается на
 * `plathix/modules/boot`, runtime навешивается в boot() под is_admin() — submenu через
 * ProPage (page-view) + 2 admin-post обработчика лицензии через ProLicenseActions
 * ([internal] #103: обработка вынесена из ProPage). Файл ProPage пока в namespace
 * Plathix\Admin (legacy) — перенос отложен в общий target [internal].
 *
 * NOTE: этот класс только регистрирует Free-страницу/форму лицензионного ключа, в
 * сетевой верификации не участвует — реальную проверку ключа на license-сервере делает
 * PRO (`PlathixPro\Modules\License\Module`).
 */
class Module implements ModuleInterface
{
	/** Фаза 1: только подписка на фазу 2. */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/** Фаза 2: регистрация PRO-страницы (submenu) + admin-post обработчиков лицензии. */
	public function boot(): void
	{
		if ( is_admin() ) {
			( new ProPage() )->register();
			( new ProLicenseActions() )->register();

			// CSS ProPage co-located в Pro-модуле ([internal], #113): грузит
			// propage.css только на странице ProPage, а не через общий admin-ui.css везде.
			( new ProPageAssets() )->register();
		}
	}
}
