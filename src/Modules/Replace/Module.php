<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только объявление и подписка на фазу 2. Runtime-хуки WP здесь не
	 * вешаются (двухфазный bootstrap).
	 * Подписка на `plathix/modules/boot` — внутренняя регистрация на фазу
	 * bootstrap, не runtime-хук, поэтому в фазе 1 разрешена.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: навешивание runtime-хуков WP, когда все модули уже прошли register.
	 *
	 * REST-маршрут replace переехал в общий платформенный слой (Http\ReplaceRoutes,
	 * регистрируется из Plugin::boot — [internal]). По развёрнутому
	 * стандарту §7 модуль свой REST не несёт.
	 */
	public function boot(): void
	{
		( new AttachmentReplaceUi() )->register();
	}
}
