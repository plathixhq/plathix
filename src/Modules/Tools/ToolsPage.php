<?php

declare(strict_types=1);

namespace Plathix\Modules\Tools;

use Plathix\Core\AdminLayout;
use Plathix\User\AccessResolver;

class ToolsPage
{
	public const PAGE_SLUG = 'plathix-tools';

	public function __construct() {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ], 13 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'             => self::PAGE_SLUG,
				'label'            => __( 'Tools', 'plathix' ),
				'isPlathixPage'  => true,
				'is_flyout_anchor' => true,
				'section'          => 'main',
				'order'            => 50,
				'is_ui_page'       => true,
				'icon'             => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
			];
			return $pages;
		} );
	}

	public function enqueueScripts(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'plathix_page_' . self::PAGE_SLUG ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/tools.asset.php', with_dependencies: false );

		if ( defined( 'PLATHIX_PATH' ) && file_exists( PLATHIX_PATH . 'assets/css/tools.css' ) ) {
			wp_enqueue_style(
				'plathix-tools',
				defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/tools.css' : '',
				[ 'plathix-admin-ui' ],
				$asset['version'] ?? '1'
			);
		}
	}

	public function addPage(): void {
		add_submenu_page(
			(string) apply_filters( 'plathix/admin/rootSlug', 'plathix' ),
			__( 'Tools', 'plathix' ),
			__( 'Tools', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		AdminLayout::renderPage( self::PAGE_SLUG, function (): void {
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

				do_action( 'plathix/tools/cards' );
				?>

				<?php $this->renderExportCard(); ?>

			</div>
			<?php
		} );
	}

	private function renderExportCard(): void {
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
