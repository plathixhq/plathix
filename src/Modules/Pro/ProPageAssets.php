<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\PublicApi\AdminPageGuard;

final class ProPageAssets
{
	public const STYLE_HANDLE = 'plathix-propage';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param string $hook
	 */

	public function enqueue(string $hook = ''): void {
		if ( ! $this->isPropage( $hook ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/propage.asset.php', with_dependencies: false );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/propage.css' : '',
			[ 'plathix-admin-ui' ],
			$asset['version'] ?? '1'
		);
	}

	private function isPropage(string $hook): bool {
		return AdminPageGuard::matches( $hook, [ 'plathix_page_' . ProPage::PAGE_SLUG ], [ ProPage::PAGE_SLUG ] );
	}
}
