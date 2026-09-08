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

	public function addAction(string $hook, object $component, string $callback, int $priority = 10, int $args = 1): void {
		if ( $this->ran ) {
			throw new \LogicException("Loader::addAction() called after run() for hook '{$hook}'"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException; $hook is a hook name from plugin code, never user input, and the message goes to the error log, not to a page
		}

		$this->actions[] = compact('hook', 'component', 'callback', 'priority', 'args');
	}

	public function addFilter(string $hook, object $component, string $callback, int $priority = 10, int $args = 1): void {
		if ( $this->ran ) {
			throw new \LogicException("Loader::addFilter() called after run() for hook '{$hook}'"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing LogicException; $hook is a hook name from plugin code, never user input, and the message goes to the error log, not to a page
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
	 * @return callable
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

				return $is_filter ? ( $args[0] ?? null ) : null;
			}
		};
	}
}
