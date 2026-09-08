<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class MediaModalEnqueue
{
	/**
	 * @param callable $callback
	 * @param int|null $admin_priority
	 * @param int $media_priority
	 * @param bool $guard_admin_hook
	 */

	public static function register(
		callable $callback,
		?int $admin_priority = 10,
		int $media_priority = 10,
		bool $guard_admin_hook = false
	): void {
		if ( $admin_priority !== null ) {
			if ( ! $guard_admin_hook || is_admin() ) {
				add_action( 'admin_enqueue_scripts', $callback, $admin_priority );
			}
		}

		add_action( 'wp_enqueue_media', $callback, $media_priority );
	}
}
