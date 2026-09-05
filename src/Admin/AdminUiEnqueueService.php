<?php

declare(strict_types=1);

namespace Plathix\Admin;

final class AdminUiEnqueueService
{
	public function enqueue_for_hook(string $hook): void {
		if ( $this->is_plathix_admin_page( $hook ) ) {
			$this->enqueue_admin_ui( $hook );
		}
	}

	public function is_plathix_admin_page(string $hook): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		if ( '' !== $page && $this->is_ui_page_registered( $page ) ) {
			return true;
		}

		return $hook === 'toplevel_page_plathix';
	}

	/**
	 * Грузить ли дизайн платформы (plathix-*) на странице с этим slug. Источник правды —
	 * реестр plathix/admin/menu_pages ([internal]): страница помечена
	 * is_ui_page=true. Раньше был хардкод-список ADMIN_PAGES. Читается ЛЕНИВО — метод
	 * вызывается на admin_enqueue_scripts (после plugins_loaded), фильтр уже полон.
	 * Так дизайн доезжает и до PRO-страниц (напр. Audit), объявляющих is_ui_page.
	 */
	private function is_ui_page_registered(string $slug): bool {
		/** @var array<int, array<string, mixed>> $pages */
		$pages = (array) apply_filters( 'plathix/admin/menu_pages', [] );
		foreach ( $pages as $page ) {
			if (
				is_array( $page )
				&& ! empty( $page['is_ui_page'] )
				&& (string) ( $page['slug'] ?? '' ) === $slug
			) {
				return true;
			}
		}
		return false;
	}

	/** Проверяет, является ли hook страницей настроек Plathix (используется Assets.php для ранней остановки enqueue сайдбара). */
	public function is_plathix_settings_page(string $hook): bool {
		if ( $hook === 'plathix_page_plathix-settings' ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) ) === 'plathix-settings';
	}

	private function enqueue_admin_ui(string $hook): void {
		$asset   = $this->get_asset( 'admin-ui' );
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		$plathix_css_path = PLATHIX_ASSETS_PATH . 'css/plathix.css';
		if ( file_exists( $plathix_css_path ) ) {
			wp_enqueue_style( 'plathix-ui', PLATHIX_ASSETS_URL . 'css/plathix.css', [], $version );
		}

		$style_path = PLATHIX_ASSETS_PATH . 'css/admin-ui.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( 'plathix-admin-ui', PLATHIX_ASSETS_URL . 'css/admin-ui.css', [ 'plathix-ui' ], $version );
		}

		$script_path = PLATHIX_ASSETS_PATH . 'js/admin-ui.js';
		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				'plathix-admin-ui',
				PLATHIX_ASSETS_URL . 'js/admin-ui.js',
				(array) ( $asset['dependencies'] ?? [] ),
				$version,
				true
			);

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'plathix-admin-ui', 'plathix', PLATHIX_PATH . 'languages' );
			}
		}
	}

	/** @return array<string, mixed> */
	private function get_asset(string $name): array {
		$file = PLATHIX_ASSETS_PATH . "js/{$name}.asset.php";
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
