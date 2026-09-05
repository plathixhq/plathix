<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только подписка на фазу 2. Runtime-хуки WP здесь не вешаются
	 * (двухфазный bootstrap).
	 * Подписка на `plathix/modules/boot` — внутренняя регистрация на фазу
	 * bootstrap, не runtime-хук, поэтому в фазе 1 разрешена.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: навешивание runtime-хуков WP, когда все модули прошли register.
	 */
	public function boot(): void
	{
		if ( is_admin() ) {
			( new PresetsPage() )->register();

			// Admin-post обработчики пресетов (apply/delete/upload/scratch) + scratch-notice —
			// свой класс PresetPostActions ([internal] / [internal]), а не
			// осколком в PresetsPage. Образец раздельной регистрации page+handlers — FreeFirstRun\Module.
			( new PresetPostActions() )->register();

			// Free first-run визард вынесен в свой модуль Plathix\Modules\FreeFirstRun
			// ([internal], [internal]) — регистрируется в plathix.php
			// под plathix/modules/register, не здесь.
		}
	}
}
