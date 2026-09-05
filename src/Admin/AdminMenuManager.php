<?php

declare(strict_types=1);

namespace Plathix\Admin;

/**
 * Платформенный слой admin-меню Plathix.
 *
 * НЕ строит меню — каждая страница регистрирует себя через add_submenu_page в своём классе.
 * Этот менеджер только: (1) опознаёт «свои» экраны для enqueue ассетов меню; (2) держит
 * flyout-группу (страницы под Tools); (3) чинит подсветку parent/submenu.
 *
 * Страницы объявляют о себе через extension point — WP-фильтр `plathix/admin/menu_pages`
 * ([internal]): каждая страница в своём register()/boot() добавляет дескриптор.
 * Менеджер НЕ знает имена конкретных страниц (снят autoload-блокер Free/PRO split).
 *
 * Дескриптор:
 *   slug             string  — page slug
 *   label            string  — подпись (для flyout-меню)
 *   is_plathix_page  bool    — true → enqueue ассетов меню на этом экране
 *   is_root          bool    — true → корневая страница меню (Dashboard); parent_file/parentMenuId
 *   is_flyout        bool    — true → страница в flyout-группе под Tools
 *   is_flyout_anchor bool    — true → якорь подсветки flyout (Tools); submenu_file/anchorSlug
 *   order            int     — порядок в flyout
 */
final class AdminMenuManager
{
	private const SCRIPT_HANDLE = 'plathix-admin-menu';
	private const STYLE_HANDLE  = 'plathix-admin-menu';

	/**
	 * Кэш собранных дескрипторов на запрос (один apply_filters за запрос).
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $pages = null;

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'parent_file', [ $this, 'filter_parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'filter_submenu_file' ] );
	}

	public function enqueue_assets(string $hook): void {
		if ( ! $this->is_plathix_admin_page( $hook ) ) {
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
				$this->build_menu_config()
			);
		}
	}

	public function filter_parent_file(string $parent_file): string {
		if ( ! $this->is_flyout_page_request() ) {
			return $parent_file;
		}

		return $this->root_slug();
	}

	public function filter_submenu_file(?string $submenu_file): string {
		if ( ! $this->is_flyout_page_request() ) {
			return (string) $submenu_file;
		}

		return $this->flyout_anchor_slug();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_menu_config(): array {
		$items = [];

		foreach ( $this->flyout_pages() as $page ) {
			$slug    = (string) $page['slug'];
			$items[] = [
				'slug'  => $slug,
				'label' => (string) $page['label'],
				'url'   => admin_url( 'admin.php?page=' . $slug ),
			];
		}

		return [
			'parentMenuId' => 'toplevel_page_' . $this->root_slug(),
			'anchorSlug'   => $this->flyout_anchor_slug(),
			'currentPage'  => $this->get_current_page_slug(),
			'items'        => $items,
		];
	}

	private function is_plathix_admin_page(string $hook): bool {
		$page = $this->get_current_page_slug();

		foreach ( $this->collect_pages() as $descriptor ) {
			if ( ! empty( $descriptor['is_plathix_page'] ) && (string) $descriptor['slug'] === $page ) {
				return true;
			}
		}

		return $hook === 'toplevel_page_' . $this->root_slug();
	}

	private function is_flyout_page_request(): bool {
		$page = $this->get_current_page_slug();

		foreach ( $this->flyout_pages() as $descriptor ) {
			if ( (string) $descriptor['slug'] === $page ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Собрать дескрипторы страниц из extension point. ЛЕНИВО — первый вызов не раньше
	 * admin_enqueue_scripts/parent_file (после plugins_loaded, где страницы регистрируются).
	 * НЕ звать из register(): Admin — первый модуль, register() выполняется раньше части
	 * страниц, массив был бы неполным.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_pages(): array {
		if ( null === $this->pages ) {
			/**
			 * Фильтр-реестр страниц admin-меню Plathix.
			 * Каждая страница добавляет свой дескриптор (см. PHPDoc класса).
			 *
			 * @param array<int, array<string, mixed>> $pages
			 */
			$pages = apply_filters( 'plathix/admin/menu_pages', [] );
			$this->pages = is_array( $pages ) ? array_values( $pages ) : [];
		}

		return $this->pages;
	}

	/**
	 * Flyout-страницы (is_flyout), отсортированные по order.
	 * @return array<int, array<string, mixed>>
	 */
	private function flyout_pages(): array {
		$flyout = array_values( array_filter(
			$this->collect_pages(),
			static fn (array $p): bool => ! empty( $p['is_flyout'] )
		) );

		usort(
			$flyout,
			static fn (array $a, array $b): int => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) )
		);

		return $flyout;
	}

	private function root_slug(): string {
		foreach ( $this->collect_pages() as $descriptor ) {
			if ( ! empty( $descriptor['is_root'] ) ) {
				return (string) $descriptor['slug'];
			}
		}

		return '';
	}

	private function flyout_anchor_slug(): string {
		foreach ( $this->collect_pages() as $descriptor ) {
			if ( ! empty( $descriptor['is_flyout_anchor'] ) ) {
				return (string) $descriptor['slug'];
			}
		}

		return '';
	}

	private function get_current_page_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) );
	}
}
