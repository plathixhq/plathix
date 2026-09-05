<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Infrastructure\Logger;

final class Loader
{
	/** @var array<int, array<string, mixed>> */
	private array $actions = [];
	/** @var array<int, array<string, mixed>> */
	private array $filters = [];
	private bool $ran = false;

	public function add_action(string $hook, object $component, string $callback, int $priority = 10, int $args = 1): void {
		if ( $this->ran ) {
			throw new \LogicException("Loader::add_action() called after run() for hook '{$hook}'"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException; $hook is a hook name from plugin code, never user input, and the message goes to the error log, not to a page
		}

		$this->actions[] = compact('hook', 'component', 'callback', 'priority', 'args');
	}

	public function add_filter(string $hook, object $component, string $callback, int $priority = 10, int $args = 1): void {
		if ( $this->ran ) {
			throw new \LogicException("Loader::add_filter() called after run() for hook '{$hook}'"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException; $hook is a hook name from plugin code, never user input, and the message goes to the error log, not to a page
		}

		$this->filters[] = compact('hook', 'component', 'callback', 'priority', 'args');
	}

	public function run(): void {
		foreach ( $this->actions as $action ) {
			add_action($action['hook'], self::wrap($action['hook'], $action['component'], $action['callback'], false), $action['priority'], $action['args']);
		}

		foreach ( $this->filters as $filter ) {
			add_filter($filter['hook'], self::wrap($filter['hook'], $filter['component'], $filter['callback'], true), $filter['priority'], $filter['args']);
		}

		$this->ran = true;
	}

	/**
	 * [internal] (Слой 1): защитная изоляция наших хук-колбэков.
	 *
	 * В зоопарке плагинов чужой фатал в общей очереди хуков нас не касается напрямую, НО
	 * наш собственный сбой на экзотической конфигурации НЕ должен ронять очередь WP и уносить
	 * с собой соседей (мы для них — такой же сторонний плагин). Оборачиваем каждый наш колбэк:
	 * ловим \Throwable (и Error, и Exception), логируем с диагностическим следом и продолжаем.
	 *
	 * Для фильтров ($is_filter=true) при сбое возвращаем первый аргумент неизменным —
	 * контракт фильтра «верни значение» сохраняется, цепочка не рвётся.
	 *
	 * Обёртка не заменяет lazy self-heal (Слой 2): она защищает от НАШЕГО исключения, но не
	 * от того, что чужой фатал оборвёт очередь ДО нашего колбэка — там спасает только
	 * ленивое восстановление на пути чтения дерева.
	 *
	 * @return callable Обёрнутый колбэк для add_action/add_filter.
	 */
	private static function wrap(string $hook, object $component, string $callback, bool $is_filter): callable {
		return static function (...$args) use ($hook, $component, $callback, $is_filter) {
			try {
				return $component->{$callback}( ...$args );
			} catch ( \Throwable $e ) {
				Logger::error(
					'hook_callback_failed',
					[
						'hook'     => $hook,
						'callback' => get_class( $component ) . '::' . $callback,
					],
					$e
				);

				// Фильтр обязан вернуть значение — отдаём вход неизменным, чтобы не сломать цепочку.
				return $is_filter ? ( $args[0] ?? null ) : null;
			}
		};
	}
}
