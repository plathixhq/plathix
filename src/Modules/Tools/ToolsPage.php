<?php

declare(strict_types=1);

namespace Plathix\Modules\Tools;

use Plathix\Core\AdminLayout;
use Plathix\User\AccessResolver;

/**
 * Страница Tools (Free-контейнер).
 *
 * Содержит Free-операции: экспорт структуры как preset ZIP, плюс слот для карточек модулей.
 * Карточка импорта из сторонних плагинов вынесена в Modules\Import\ImportToolsCard
 * ([internal]) — подписывается на слот `plathix/tools/cards` prio 10.
 * PRO-карточка выдачи REST service-токенов ВЫРЕЗАНА ([internal]): страница
 * публикует extension point `do_action('plathix/tools/cards')`, PRO-модуль ApiKey рендерит
 * свою карточку через подписку на prio 5.
 */
class ToolsPage
{
	public const PAGE_SLUG = 'plathix-tools';

	public function __construct() {
	}


	/** Регистрирует страницу в WP-меню. */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 13 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'             => self::PAGE_SLUG,
				'label'            => __( 'Tools', 'plathix' ),
				'is_plathix_page'  => true,
				'is_flyout_anchor' => true,
				'section'          => 'main',
				'order'            => 50,
				'is_ui_page'       => true,
				'icon'             => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
			];
			return $pages;
		} );
	}

	/**
	 * CSS страницы Tools co-located ([internal]): вынесен из общего admin-ui.css
	 * в tools.css, грузится только здесь. Несамодостаточен (переменные из admin-ui.css) —
	 * dep plathix-admin-ui, который грузится на Tools (is_ui_page). Только export-карточка
	 * хоста; ApiKey/Import карточки грузят свой CSS сами (ApiKeyToolsCard в PRO, import-grid.css).
	 */
	public function enqueue_scripts(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'plathix_page_' . self::PAGE_SLUG ) {
			return;
		}

		// Версия — из tools.asset.php (пара CSS-only entry `tools`, [internal], #594):
		// контент-хеш меняется от правки CSS → cache-busting без bump версии плагина.
		// Зеркало ProPageAssets/WizardAssets; fallback на версию плагина, если пары нет.
		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/tools.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		if ( defined( 'PLATHIX_PATH' ) && file_exists( PLATHIX_PATH . 'assets/css/tools.css' ) ) {
			wp_enqueue_style(
				'plathix-tools',
				defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/tools.css' : '',
				[ 'plathix-admin-ui' ],
				$asset['version'] ?? '1'
			);
		}
	}

	/** Добавляет submenu-пункт Tools. */
	public function add_page(): void {
		add_submenu_page(
			(string) apply_filters( 'plathix/admin/root_slug', 'plathix' ),
			__( 'Tools', 'plathix' ),
			__( 'Tools', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/** Точка входа — рендерит страницу. */
	public function render(): void {
		AdminLayout::render_page( self::PAGE_SLUG, function (): void {
			if ( ! AccessResolver::currentUserIsFullAdmin() ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}

			?>
			<div class="plathix-page">

				<div class="plathix-page__head">
					<div>
						<h1 class="plathix-page__title"><?php esc_html_e( 'Tools', 'plathix' ); ?></h1>
						<div class="plathix-page__desc"><?php esc_html_e( 'Data operations, migrations and integrations — things you run occasionally, not configure daily.', 'plathix' ); ?></div>
					</div>
				</div>

				<?php
				/**
				 * Extension point: модули добавляют свои карточки на страницу Tools.
				 * ApiKey (PRO, prio 5) → Import (Free, prio 10) → далее по приоритету.
				 * Стоит ПОСЛЕ caps-гейта render() (Full + manage_options).
				 */
				do_action( 'plathix/tools/cards' );
				?>

				<?php $this->render_export_card(); ?>

			</div>
			<?php
		} );
	}

	/**
	 * Карточка экспорта текущей структуры папок как preset ZIP.
	 */
	private function render_export_card(): void {
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title plathix-tools__title-with-icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					<?php esc_html_e( 'Export Structure', 'plathix' ); ?>
				</span>
			</div>
			<div class="plathix-card__body">
				<p class="plathix-field__desc plathix-tools__export-desc">
					<?php esc_html_e( 'Export your entire folder structure as a portable preset ZIP file. The package can be imported on any other site running Plathix.', 'plathix' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'plathix_export_preset', 'plathix_export_preset_nonce' ); ?>
					<input type="hidden" name="action" value="plathix_export_preset">
					<button type="submit" class="plathix-btn plathix-btn--primary plathix-tools__export-btn">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export to ZIP', 'plathix' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}
}
