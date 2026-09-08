<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class TempDirectory
{

	public function path(): string
	{
		$temp_name = 'plathix-temp' . ( is_multisite() ? '-' . get_current_blog_id() : '' );
		$preferred = [
			\rtrim( \sys_get_temp_dir(), '/\\' ) . '/' . $temp_name,
		];
		$upload     = \wp_upload_dir();
		$fallback   = \trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'plathix-temp';
		$configured = \apply_filters( 'plathix/infrastructure/temp_dir', '' );

		if ( \is_string( $configured ) && $configured !== '' ) {
			return \trailingslashit( $configured );
		}

		foreach ( $preferred as $candidate ) {
			if ( @\is_dir( $candidate ) || @\wp_mkdir_p( $candidate ) ) {
				return \trailingslashit( $candidate );
			}
		}

		return \trailingslashit( $fallback );
	}

	/**
	 * @param string $dir
	 */

	public static function removeTree(string $dir): void
	{
		if ( $dir === '' || ! \is_dir( $dir ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP has no directory-removal API; WP_Filesystem would demand FTP credentials for a directory this plugin created itself
				\rmdir( $entry->getRealPath() );
			} else {
				\wp_delete_file( $entry->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the now-empty directory after its realpath-resolved contents were deleted above
		\rmdir( $dir );
	}

	public static function isUnderRoot(string $real_path, string $real_root): bool
	{
		return $real_path === $real_root
			|| str_starts_with( $real_path, $real_root . DIRECTORY_SEPARATOR );
	}
}
