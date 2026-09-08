<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

final class WizardAssets
{
	public const STYLE_HANDLE = 'plathix-free-wizard';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param string $hook
	 */

	public function enqueue(string $hook = ''): void {
		if ( ! $this->isPlathixPage( $hook ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/free-wizard.asset.php', with_dependencies: false );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/free-wizard.css' : '',
			[],
			$asset['version'] ?? '1'
		);
	}

	private function isPlathixPage(string $hook): bool {
		if ( $hook === 'toplevel_page_plathix' ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution deciding whether the wizard CSS loads on this admin page; sanitized (sanitize_key), no form processing, no DB write
		return isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'plathix';
	}
}
