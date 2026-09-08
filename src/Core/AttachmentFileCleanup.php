<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\TempDirectory;

final class AttachmentFileCleanup
{
	/**
	 * @param array<string, mixed> $old_metadata
	 * @param list<string> $new_size_paths
	 * @return list<string>
	 */

	public function cleanup(?string $old_file, array $old_metadata, ?string $new_file, array $new_size_paths = []): array
	{
		$warnings = [];
		$paths = $this->collectPaths( $old_file, $old_metadata );
		$new_real = $this->normalizePath( $new_file );
		$new_real_size_paths = array_map( [ $this, 'normalizePath' ], $new_size_paths );
		$upload_root = $this->resolveUploadRoot();

		foreach ( $paths as $path ) {
			$real = $this->normalizePath( $path );
			if ( $real === '' || $real === $new_real || in_array( $real, $new_real_size_paths, true ) ) {
				continue;
			}

			if ( $upload_root !== '' && ! TempDirectory::isUnderRoot( $real, $upload_root ) ) {
				$warnings[] = sprintf( 'Cleanup skipped file outside allowed root: %s', basename( $real ) );
				continue;
			}

			if ( ! file_exists( $real ) ) {
				$warnings[] = sprintf( 'Cleanup skipped missing file: %s', basename( $real ) );
				continue;
			}

			if ( ! @unlink( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- $real is realpath()-resolved and asserted isUnderRoot(uploads) before delete; not wp_delete_file() to keep the return value for failure reporting.
				$warnings[] = sprintf( 'Cleanup failed for file: %s', basename( $real ) );
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	private function resolveUploadRoot(): string
	{
		$upload_dir = wp_upload_dir();
		$base = (string) ( $upload_dir['basedir'] ?? '' );
		if ( $base === '' ) {
			return '';
		}

		$real = realpath( $base );

		return is_string( $real ) && $real !== '' ? $real : $base;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return list<string>
	 */
	private function collectPaths(?string $old_file, array $metadata): array
	{
		$paths = [];
		if ( is_string( $old_file ) && $old_file !== '' ) {
			$paths[] = $old_file;
		}

		$base_dir = $old_file ? dirname( $old_file ) : '';
		if ( $base_dir !== '' ) {
			$this->collectSizePaths( $paths, $metadata['sizes'] ?? null, $base_dir );

			$original_image = (string) ( $metadata['original_image'] ?? '' );
			if ( $original_image !== '' ) {
				$paths[] = $base_dir . '/' . ltrim( $original_image, '/' );
			}

			$backup_sizes = $metadata['backup_sizes'] ?? null;
			if ( is_array( $backup_sizes ) ) {
				foreach ( $backup_sizes as $backup ) {
					if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
						$paths[] = $base_dir . '/' . ltrim( (string) $backup['file'], '/' );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $paths, 'is_string' ) ) );
	}

	/**
	 * @param list<string> $paths
	 */
	private function collectSizePaths(array &$paths, mixed $sizes, string $base_dir): void
	{
		if ( ! is_array( $sizes ) ) {
			return;
		}

		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}

			$paths[] = $base_dir . '/' . ltrim( (string) $size['file'], '/' );
		}
	}

	private function normalizePath(?string $path): string
	{
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		$real = realpath( $path );

		return is_string( $real ) && $real !== '' ? $real : $path;
	}
}
