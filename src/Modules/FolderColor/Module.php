<?php

declare(strict_types=1);

namespace Plathix\Modules\FolderColor;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\MediaModalEnqueue;

class Module implements ModuleInterface
{
	public function register(): void {
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void {
		add_filter( 'plathix/sidebar/i18n', [ $this, 'addI18n' ] );

		MediaModalEnqueue::register( [ $this, 'enqueueScripts' ], 20, 20 );
	}

	/**
	 * @param array<string, string> $strings
	 * @return array<string, string>
	 */
	public function addI18n(array $strings): array {
		$strings['color_label'] = __( 'Color', 'plathix' );
		return $strings;
	}

	public function enqueueScripts(): void {
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/color.asset.php' );

		wp_enqueue_script(
			'plathix-color',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/color.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], $asset['dependencies'] ?? [] ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style(
			'plathix-color',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/color.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}
}
