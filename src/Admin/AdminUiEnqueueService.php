<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\PublicApi\SettingsApi;

final class AdminUiEnqueueService
{
	public function enqueueForHook(string $hook): void {
		if ( $this->isPlathixAdminPage( $hook ) ) {
			$this->enqueueAdminUi( $hook );
		}
	}

	public function isPlathixAdminPage(string $hook): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		if ( '' !== $page && $this->isUiPageRegistered( $page ) ) {
			return true;
		}

		return $hook === 'toplevel_page_plathix';
	}

	private function isUiPageRegistered(string $slug): bool {
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

	public function isPlathixSettingsPage(string $hook): bool {

		// SettingsPage::PAGE_SLUG.
		$slug = ( new SettingsApi() )->pageSlug();
		if ( $hook === 'plathix_page_' . $slug ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) ) === $slug;
	}

	private function enqueueAdminUi(string $hook): void {
		$asset   = $this->getAsset( 'admin-ui' );
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
	private function getAsset(string $name): array {
		return \Plathix\Infrastructure\AssetManifest::read( "js/{$name}.asset.php" );
	}
}
