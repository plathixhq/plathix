<?php

declare(strict_types=1);

namespace Plathix\Admin;

final class AdminMenuManager
{
	private const SCRIPT_HANDLE = 'plathix-admin-menu';
	private const STYLE_HANDLE  = 'plathix-admin-menu';

	private ?array $pages = null;

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
		add_filter( 'parent_file', [ $this, 'filterParentFile' ] );
		add_filter( 'submenu_file', [ $this, 'filterSubmenuFile' ] );
	}

	public function enqueueAssets(string $hook): void {
		if ( ! $this->isPlathixAdminPage( $hook ) ) {
			return;
		}

		$version = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? (string) time() : PLATHIX_VERSION;
		$style_path = PLATHIX_ASSETS_PATH . 'css/admin-menu.css';
		$script_path = PLATHIX_ASSETS_PATH . 'js/admin-menu.js';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				PLATHIX_ASSETS_URL . 'css/admin-menu.css',
				[],
				$version
			);
		}

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				PLATHIX_ASSETS_URL . 'js/admin-menu.js',
				[],
				$version,
				true
			);

			wp_localize_script(
				self::SCRIPT_HANDLE,
				'PlathixAdminMenu',
				$this->buildMenuConfig()
			);
		}
	}

	public function filterParentFile(string $parent_file): string {
		if ( ! $this->isFlyoutPageRequest() ) {
			return $parent_file;
		}

		return $this->rootSlug();
	}

	public function filterSubmenuFile(?string $submenu_file): string {
		if ( ! $this->isFlyoutPageRequest() ) {
			return (string) $submenu_file;
		}

		return $this->flyoutAnchorSlug();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildMenuConfig(): array {
		$items = [];

		foreach ( $this->flyoutPages() as $page ) {
			$slug    = (string) $page['slug'];
			$items[] = [
				'slug'  => $slug,
				'label' => (string) $page['label'],
				'url'   => admin_url( 'admin.php?page=' . $slug ),
			];
		}

		return [
			'parentMenuId' => 'toplevel_page_' . $this->rootSlug(),
			'anchorSlug'   => $this->flyoutAnchorSlug(),
			'currentPage'  => $this->getCurrentPageSlug(),
			'items'        => $items,
		];
	}

	private function isPlathixAdminPage(string $hook): bool {
		$page = $this->getCurrentPageSlug();

		foreach ( $this->collectPages() as $descriptor ) {
			if ( ! empty( $descriptor['isPlathixPage'] ) && (string) $descriptor['slug'] === $page ) {
				return true;
			}
		}

		return $hook === 'toplevel_page_' . $this->rootSlug();
	}

	private function isFlyoutPageRequest(): bool {
		$page = $this->getCurrentPageSlug();

		foreach ( $this->flyoutPages() as $descriptor ) {
			if ( (string) $descriptor['slug'] === $page ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */

	private function collectPages(): array {
		if ( null === $this->pages ) {
			/**
			 * @param array<int, array<string, mixed>> $pages
			 */

			$pages = apply_filters( 'plathix/admin/menu_pages', [] );
			$this->pages = is_array( $pages ) ? array_values( $pages ) : [];
		}

		return $this->pages;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */

	private function flyoutPages(): array {
		$flyout = array_values( array_filter(
			$this->collectPages(),
			static fn (array $p): bool => ! empty( $p['is_flyout'] )
		) );

		usort(
			$flyout,
			static fn (array $a, array $b): int => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) )
		);

		return $flyout;
	}

	private function rootSlug(): string {
		foreach ( $this->collectPages() as $descriptor ) {
			if ( ! empty( $descriptor['is_root'] ) ) {
				return (string) $descriptor['slug'];
			}
		}

		return '';
	}

	private function flyoutAnchorSlug(): string {
		foreach ( $this->collectPages() as $descriptor ) {
			if ( ! empty( $descriptor['is_flyout_anchor'] ) ) {
				return (string) $descriptor['slug'];
			}
		}

		return '';
	}

	private function getCurrentPageSlug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) );
	}
}
