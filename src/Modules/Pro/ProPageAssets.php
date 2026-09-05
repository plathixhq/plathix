<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\PublicApi\AdminPageGuard;

/**
 * Доставка CSS страницы ProPage ([internal], #113).
 *
 * Зеркало {@see \Plathix\Modules\FreeFirstRun\WizardAssets}, CSS-ONLY: ProPage
 * ({@see ProPage}) — server-side PHP-рендер апселл-страницы «Перейти на PRO» (hero,
 * таблица сравнения, планы), у неё НЕТ JS-логики. Поэтому здесь только
 * `wp_enqueue_style` — без script/localize.
 *
 * Раньше `.plathix-pro-*` жил в общем admin-ui.css и грузился на КАЖДОЙ admin-странице. Теперь
 * Pro-модуль владеет своим CSS-бандлом propage.css и грузит его только на странице ProPage.
 *
 * propage.css НЕ самодостаточен (использует `.plathix-btn*`/`.plathix-card*`/переменные из admin-ui.css)
 * — валиден, т.к. admin-ui.css грузится на той же странице ProPage (`is_ui_page`); dep
 * `['plathix-admin-ui']` гарантирует порядок. Механизм = CSS-only standalone-entry (webpack
 * entry `propage`), зеркало free-wizard.
 */
final class ProPageAssets
{
	public const STYLE_HANDLE = 'plathix-propage';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * На странице ProPage грузит CSS-бандл апселла.
	 *
	 * @param string $hook Текущий admin-хук от WordPress.
	 */
	public function enqueue(string $hook = ''): void {
		if ( ! $this->is_propage( $hook ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/propage.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_style(
			self::STYLE_HANDLE,
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/propage.css' : '',
			[ 'plathix-admin-ui' ],
			$asset['version'] ?? '1'
		);
	}

	/**
	 * Узкая проверка «это страница ProPage» — апселл-CSS живёт только там.
	 * Механизм сравнения делегирован в {@see AdminPageGuard} ([internal], cross-repo часть).
	 */
	private function is_propage(string $hook): bool {
		return AdminPageGuard::matches( $hook, [ 'plathix_page_' . ProPage::PAGE_SLUG ], [ ProPage::PAGE_SLUG ] );
	}
}
