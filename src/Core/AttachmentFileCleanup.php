<?php

declare(strict_types=1);

namespace Plathix\Core;

final class AttachmentFileCleanup
{
	/**
	 * @param array<string, mixed> $old_metadata
	 * @param list<string> $new_size_paths [internal]: пути новых thumbnail-файлов (вычисленные
	 *        вызывающей стороной из $new_metadata['sizes'] + dirname($new_file)). Явный
	 *        override non-goal [internal] ("не меняем поведение AttachmentFileCleanup") —
	 *        тот non-goal не учитывал path-коллизию reuse_old_filename() ([internal]): если
	 *        новый thumbnail совпадает путём со старым (тот же stem), эта функция раньше
	 *        удаляла бы только что созданный новый файл, потому что сравнивала каждый старый
	 *        путь только с ОДНИМ $new_file (главным файлом), не со списком новых size-путей.
	 * @return list<string>
	 */
	public function cleanup(?string $old_file, array $old_metadata, ?string $new_file, array $new_size_paths = []): array
	{
		$warnings = [];
		$paths = $this->collect_paths( $old_file, $old_metadata );
		$new_real = $this->normalize_path( $new_file );
		$new_real_size_paths = array_map( [ $this, 'normalize_path' ], $new_size_paths );
		$upload_root = $this->resolve_upload_root();

		foreach ( $paths as $path ) {
			$real = $this->normalize_path( $path );
			if ( $real === '' || $real === $new_real || in_array( $real, $new_real_size_paths, true ) ) {
				continue;
			}

			if ( $upload_root !== '' && ! $this->is_under_root( $real, $upload_root ) ) {
				$warnings[] = sprintf( 'Cleanup skipped file outside allowed root: %s', basename( $real ) );
				continue;
			}

			if ( ! file_exists( $real ) ) {
				$warnings[] = sprintf( 'Cleanup skipped missing file: %s', basename( $real ) );
				continue;
			}

			if ( ! @unlink( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- $real is realpath()-resolved and asserted is_under_root(uploads) before delete; not wp_delete_file() to keep the return value for failure reporting.
				$warnings[] = sprintf( 'Cleanup failed for file: %s', basename( $real ) );
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	private function resolve_upload_root(): string
	{
		$upload_dir = wp_upload_dir();
		$base = (string) ( $upload_dir['basedir'] ?? '' );
		if ( $base === '' ) {
			return '';
		}

		$real = realpath( $base );

		return is_string( $real ) && $real !== '' ? $real : $base;
	}

	private function is_under_root(string $real_path, string $real_root): bool
	{
		return $real_path === $real_root
			|| str_starts_with( $real_path, $real_root . DIRECTORY_SEPARATOR );
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return list<string>
	 */
	private function collect_paths(?string $old_file, array $metadata): array
	{
		$paths = [];
		if ( is_string( $old_file ) && $old_file !== '' ) {
			$paths[] = $old_file;
		}

		$base_dir = $old_file ? dirname( $old_file ) : '';
		if ( $base_dir !== '' ) {
			$this->collect_size_paths( $paths, $metadata['sizes'] ?? null, $base_dir );

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
	private function collect_size_paths(array &$paths, mixed $sizes, string $base_dir): void
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

	private function normalize_path(?string $path): string
	{
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		$real = realpath( $path );

		return is_string( $real ) && $real !== '' ? $real : $path;
	}
}
