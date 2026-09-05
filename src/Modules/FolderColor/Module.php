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
		add_filter( 'plathix/sidebar/i18n', [ $this, 'add_i18n' ] );
		// [internal] (тот же корень, что #31): admin_enqueue_scripts не срабатывает в
		// редакторе Elementor. wp_enqueue_media — нативный WP-хук media-picker-контекста.
		// [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
		MediaModalEnqueue::register( [ $this, 'enqueue_scripts' ], 20, 20 );
	}

	/**
	 * @param array<string, string> $strings
	 * @return array<string, string>
	 */
	public function add_i18n(array $strings): array {
		$strings['color_label'] = __( 'Color', 'plathix' );
		return $strings;
	}

	/**
	 * Энкьюит бандл фичи цвета ([internal]): пикер + показ + самомонтаж в контекст-меню.
	 * По образцу SearchFilters/Module — зависит от plathix-sidebar, грузится defer только там, где
	 * поднят сайдбар. Фича цвета целиком под этим модулем (JS + i18n); правка цвета = только модуль
	 * color/ + этот enqueue. Вынос в PRO = перенос entry + этого enqueue.
	 */
	public function enqueue_scripts(): void {
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/color.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_script(
			'plathix-color',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/color.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], (array) ( $asset['dependencies'] ?? [] ) ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// CSS редактора цвета уехал из sidebar.css в color.css (собирается webpack из
		// color-entry import). Энкьюим как отдельный стиль, зависящий от plathix-sidebar.
		wp_enqueue_style(
			'plathix-color',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/color.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}
}
