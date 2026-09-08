<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class AssetManifest
{
	/**
	 * @param string      $relative_path
	 * @param bool        $with_dependencies
	 * @param string|null $base_path
	 * @return array{version: string, dependencies?: array<int, string>}
	 */

	public static function read(string $relative_path, bool $with_dependencies = true, ?string $base_path = null): array {
		$file  = ( $base_path ?? PLATHIX_ASSETS_PATH ) . $relative_path;
		$asset = file_exists( $file ) ? require $file : null;

		$version = is_array( $asset ) && isset( $asset['version'] ) ? (string) $asset['version'] : PLATHIX_VERSION;

		if ( ! $with_dependencies ) {
			return [ 'version' => $version ];
		}

		$dependencies = is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: [];

		return [ 'version' => $version, 'dependencies' => $dependencies ];
	}
}
