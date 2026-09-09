<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Admin\AdminLayoutNav;

class AdminLayout
{
	public static function open(string $current_page): void {
		$plathix_current_page = $current_page; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

		$plathix_nav_sections = AdminLayoutNav::sections(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- prefixed local handed to the required template partial; the prefix prevents collisions with variables in the including scope
		require PLATHIX_PATH . 'views/admin-layout.php';
	}

	public static function close(): void {
		require PLATHIX_PATH . 'views/admin-layout-end.php';
	}

	/**
	 * @param string   $slug
	 * @param callable $body
	 */

	public static function renderPage(string $slug, callable $body): void {
		ob_start();
		$body();
		$html = (string) ob_get_clean();

		if ( '' === trim( $html ) ) {
			return;

		}

		self::open( $slug );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body already escaped its own output at echo time; the ob buffer is a byte passthrough of markup this plugin produced, re-escaping would double-escape the page.
		self::close();
	}
}
