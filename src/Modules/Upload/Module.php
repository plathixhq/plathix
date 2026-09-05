<?php

declare(strict_types=1);

namespace Plathix\Modules\Upload;

use Plathix\Contracts\ModuleInterface;
use Plathix\Core\MediaUploadEnqueue;
use Plathix\Core\Upload;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;

/**
 * Тонкая модульная обёртка авто-распределения загрузок по папкам.
 *
 * `Upload` (`Plathix\Core\Upload`) исторически бутстрапился напрямую в `Plugin::boot()`
 * (`new Upload($this->loader)`). Эта обёртка переводит создание на стандартный двухфазный
 * bootstrap без физического переноса класса и смены namespace (`Plathix\Core` — legacy
 * tolerated, мигрирует единым витком [internal]).
 *
 * Единственная платформенная зависимость — `Loader` (через него `Upload` в своём ctor
 * навешивает хук `add_attachment`). Loader приходит АРГУМЕНТОМ хука `plathix/modules/boot`
 * (Опция A — общий платформенный экземпляр, тот же, что у Rest; НЕ пересоздаём, иначе хук
 * не попал бы в общий `Loader::run()`). Другие модули (boot(): void) лишние аргументы
 * игнорируют.
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
	 * Фаза 2: создаёт Upload из платформенного Loader, пришедшего аргументом хука.
	 *
	 * Upload в своём конструкторе навешивает `add_attachment` → `assign_folder_on_upload`.
	 * boot стреляет до `Loader::run()`, поэтому хук успевает зарегистрироваться.
	 * Дефолты null + guard: если модуль вызван без сервисов (вне Plugin-контекста), Upload
	 * не создаётся — без фатала (как Rest).
	 *
	 * @param JobDispatcher|null $jobs         Не используется Upload (контракт хука).
	 * @param RateLimiter|null   $rate_limiter Не используется Upload (контракт хука).
	 * @param Loader|null        $loader       Платформенный Loader (вешает add_attachment).
	 */
	public function boot(?JobDispatcher $jobs = null, ?RateLimiter $rate_limiter = null, ?Loader $loader = null): void
	{
		if ( $loader !== null ) {
			new Upload( $loader );
			// [internal] ([internal]): грузит media-upload.js только на media-new.php,
			// см. MediaUploadEnqueue.
			new MediaUploadEnqueue( $loader );
		}
	}
}
