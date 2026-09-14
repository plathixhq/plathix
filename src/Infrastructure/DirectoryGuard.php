<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class DirectoryGuard
{
	public static function ensure(string $dir): void
	{
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes a directory-index guard into a plugin-owned managed upload dir; WP_Filesystem credentials-flow may be unavailable on this path.
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes an Apache deny-all guard into a plugin-owned managed upload dir; WP_Filesystem credentials-flow may be unavailable on this path.
		}
	}
}
