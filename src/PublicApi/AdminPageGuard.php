<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

final class AdminPageGuard
{
	/**
	 * @param string   $hook
	 * @param string[] $hooks
	 * @param string[] $pages
	 */

	public static function matches(string $hook, array $hooks, array $pages): bool {
		if ( in_array( $hook, $hooks, true ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution deciding whether the caller's CSS/JS loads on this admin page; sanitized (sanitize_key), no form processing, no DB write
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, $pages, true );
	}
}
