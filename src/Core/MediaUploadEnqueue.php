<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

/**
 * [internal] ([internal]): media-new.php не имеет sidebar/upload-events.js —
 * отдельный JS-контекст, не патчит XHR аплоада. Грузит узкий media-upload.js только на
 * этой странице, чтобы plupload-запрос async-upload.php наследовал plathix_folder,
 * дописанный в href кнопки "Добавить медиафайл" (upload-link-context.js, [internal]).
 */
final class MediaUploadEnqueue
{
	private const SCRIPT_HANDLE = 'plathix-media-upload';

	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue' );
	}

	public function enqueue(string $hook_suffix): void {
		if ( 'media-new.php' !== $hook_suffix ) {
			return;
		}

		$path = PLATHIX_ASSETS_PATH . 'js/media-upload.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset   = $this->get_asset();
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
	private function get_asset(): array {
		$file = PLATHIX_ASSETS_PATH . 'js/media-upload.asset.php';
		if ( file_exists( $file ) ) {
			$asset = require $file;
			if ( is_array( $asset ) ) {
				return $asset;
			}
		}

		return [
			'version'      => PLATHIX_VERSION,
			'dependencies' => [],
		];
	}
}
