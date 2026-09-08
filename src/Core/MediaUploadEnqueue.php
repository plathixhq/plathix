<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class MediaUploadEnqueue
{
	private const SCRIPT_HANDLE = 'plathix-media-upload';

	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->addAction( 'admin_enqueue_scripts', $this, 'enqueue' );
	}

	public function enqueue(string $hook_suffix): void {
		if ( 'media-new.php' !== $hook_suffix ) {
			return;
		}

		$path = PLATHIX_ASSETS_PATH . 'js/media-upload.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset   = $this->getAsset();
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PLATHIX_ASSETS_URL . 'js/media-upload.js',
			(array) ( $asset['dependencies'] ?? [] ),
			$version,
			true
		);
	}

	/** @return array<string, mixed> */
	private function getAsset(): array {
		return \Plathix\Infrastructure\AssetManifest::read( 'js/media-upload.asset.php' );
	}
}
