<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

/**
 * Доставка CSS Free first-run визарда ([internal], #113).
 *
 * Зеркало PRO {@see \PlathixPro\Modules\Onboarding\WizardAssets}, но CSS-ONLY: Free-визард
 * ({@see FreeWizard}) — server-side PHP-рендер overlay со статичными `<a href>` на admin-post
 * (skip/apply/scratch), у него НЕТ JS-логики (в отличие от PRO SPA). Поэтому здесь только
 * `wp_enqueue_style` — без `wp_enqueue_script`/`wp_localize_script`.
 *
 * Раньше `.plathix-wizard*` жил в общем admin-ui.css и грузился на КАЖДОЙ admin-странице. Теперь
 * FreeFirstRun владеет своим CSS-бандлом free-wizard.css и грузит его только на Dashboard,
 * где рендерится визард.
 *
 * free-wizard.css НЕ самодостаточен (использует CSS-переменные и btn/badge-классы из
 * admin-ui.css) — валиден, т.к. admin-ui.css грузится на том же toplevel_page_plathix.
 * Осознанное зеркало PRO onboarding-wizard.css. Стиль грузится на всём Dashboard, даже когда
 * визард не показан (should_show_wizard=false) — симметрия с PRO (там тоже стиль на всей
 * plathix-странице); гейт по показу НЕ вводим ради единообразия.
 */
final class WizardAssets
{
	public const STYLE_HANDLE = 'plathix-free-wizard';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * На странице plathix (Dashboard) грузит CSS-бандл визарда.
	 *
	 * @param string $hook Текущий admin-хук от WordPress.
	 */
	public function enqueue(string $hook = ''): void {
		if ( ! $this->is_plathix_page( $hook ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/free-wizard.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_style(
			self::STYLE_HANDLE,
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/free-wizard.css' : '',
			[],
			$asset['version'] ?? '1'
		);
	}

	/**
	 * Узкая проверка «это страница plathix» (Dashboard) — визард first-run живёт только там.
	 * Зеркалит PRO WizardAssets::is_plathix_page.
	 */
	private function is_plathix_page(string $hook): bool {
		if ( $hook === 'toplevel_page_plathix' ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution deciding whether the wizard CSS loads on this admin page; sanitized (sanitize_key), no form processing, no DB write
		return isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'plathix';
	}
}
