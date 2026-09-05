<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\Features;

/**
 * Тонкая модульная обёртка Import-фичи (импорт структуры папок из плагинов-конкурентов:
 * HappyFiles, FileBird, Real Media Library, WP Media Folder, Wicked Folders).
 *
 * `ImportManager` физически перенесён в `Plathix\Modules\Import` ([internal]) —
 * бутстрапится напрямую в `Plugin::boot()` (`new ImportManager(loader: ...)` под флагом
 * `import`). Эта обёртка переводит его на стандартный двухфазный bootstrap.
 *
 * Прецеденты: Modules\Svg\Module, Modules\Gallery\Module.
 */
final class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только подписка на фазу 2. Runtime-хуки WP здесь не вешаются.
	 * Подписка на `plathix/modules/boot` — внутренняя регистрация на фазу bootstrap.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: под feature-флагом `import` инстанцирует ImportManager и навешивает:
	 * - `plathix/import/job` через ImportManager::register() — обработчик фоновой задачи;
	 * - ImportToolsCard на слот `plathix/tools/cards` — карточка импорта на странице Tools.
	 */
	public function boot(): void
	{
		if ( ! Features::is_enabled( 'import' ) ) {
			return;
		}

		$manager = new ImportManager();
		$manager->register();

		( new ImportAjaxHandler() )->register();

		if ( is_admin() ) {
			( new ImportToolsCard( $manager ) )->register();
			( new ImportEnqueueService() )->register();
		}
	}
}
