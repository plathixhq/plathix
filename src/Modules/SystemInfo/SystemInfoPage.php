<?php

declare(strict_types=1);

namespace Plathix\Modules\SystemInfo;

use Plathix\Core\AdminLayout;
use Plathix\User\AccessResolver;

class SystemInfoPage
{
	public const PAGE_SLUG = 'plathix-system-info';

	private ?SystemInfoProvider $provider = null;

	private function provider(): SystemInfoProvider {
		return $this->provider ??= new SystemInfoProvider();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ], 15 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'System Info', 'plathix' ),
				'isPlathixPage' => true,
				'is_flyout'       => true,
				'order'           => 2,
				'section'         => 'footer',
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
			];
			return $pages;
		} );
	}

	public function enqueueScripts(string $hook): void {
		if ( $hook !== 'admin_page_' . self::PAGE_SLUG ) {
			return;
		}

		$asset   = \Plathix\Infrastructure\AssetManifest::read( 'js/admin-ui/system-info.asset.php' );
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : $asset['version'];

		if ( file_exists( PLATHIX_ASSETS_PATH . 'js/admin-ui/system-info.js' ) ) {
			wp_enqueue_script(
				'plathix-system-info-ui',
				PLATHIX_ASSETS_URL . 'js/admin-ui/system-info.js',
				$asset['dependencies'] ?? [],
				$version,
				true
			);
			wp_set_script_translations( 'plathix-system-info-ui', 'plathix', PLATHIX_PATH . 'languages' );

			wp_enqueue_style(
				'plathix-system-info-ui',
				PLATHIX_ASSETS_URL . 'css/admin-ui/system-info.css',
				[ 'plathix-admin-ui' ],
				$version
			);
		}
	}

	public function addPage(): void {

		add_submenu_page(
			null,
			__( 'System Info', 'plathix' ),
			__( 'System Info', 'plathix' ),
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
					<h1 class="plathix-page__title"><?php esc_html_e( 'System Info', 'plathix' ); ?></h1>
					<div class="plathix-page__desc"><?php esc_html_e( 'Environment diagnostics and health checks.', 'plathix' ); ?></div>
				</div>
				<div class="plathix-system-info__actions">
					<button type="button" class="plathix-btn plathix-btn--sm plathix-copy-support" id="plathix-copy-sysinfo">
						<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5 1h8a1 1 0 011 1v9h-1V2H5V1zM2 4h8a1 1 0 011 1v10a1 1 0 01-1 1H2a1 1 0 01-1-1V5a1 1 0 011-1zm0 1v10h8V5H2z"/></svg>
						<?php esc_html_e( 'Copy for support', 'plathix' ); ?>
					</button>
					<span class="plathix-copy-support__notice" id="plathix-copy-sysinfo-notice">
						<?php esc_html_e( 'Copied!', 'plathix' ); ?>
					</span>
				</div>
			</div>

			<?php $this->renderSection( __( 'Health Checks', 'plathix' ), $this->provider()->healthCheckRows() ); ?>
			<?php $this->renderSection( __( 'Database Tables', 'plathix' ), $this->provider()->dbTablesRows() ); ?>
			<?php $this->renderSection( __( 'Plathix', 'plathix' ), $this->provider()->plathixInfo() ); ?>
			<?php $this->renderSection( __( 'Server Environment', 'plathix' ), $this->provider()->serverEnvironment() ); ?>
			<?php $this->renderSection( __( 'WordPress Environment', 'plathix' ), $this->provider()->wpEnvironment() ); ?>
			<?php $this->renderSection( __( 'Theme', 'plathix' ), $this->provider()->themeInfo() ); ?>
			<?php $this->renderSection( __( 'Active Plugins', 'plathix' ), $this->provider()->activePlugins() ); ?>

			</div>
			<?php
		} );
	}

	// -------------------------------------------------------------------------
	// Section renderer
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, array{label:string, value:string, ok?:bool|null}> $rows
	 */

	private function renderSection(string $title, array $rows): void {
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="plathix-card" data-sysinfo-section="<?php echo esc_attr( $title ); ?>">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php echo esc_html( $title ); ?></span>
			</div>
			<div class="plathix-table-wrap plathix-system-info__table-wrap">
				<table class="plathix-table">
					<tbody>
					<?php foreach ( $rows as $row ) :
						$ok = $row['ok'] ?? null;
						?>
						<tr data-sysinfo-label="<?php echo esc_attr( $row['label'] ); ?>" data-sysinfo-value="<?php echo esc_attr( $row['value'] ); ?>">
							<td class="plathix-system-info__label-cell"><?php echo esc_html( $row['label'] ); ?></td>
							<td>
								<?php if ( true === $ok ) : ?>
									<span class="plathix-system-info__value--ok"><?php echo esc_html( $row['value'] ); ?></span>
								<?php elseif ( false === $ok ) : ?>
									<span class="plathix-system-info__value--error"><?php echo esc_html( $row['value'] ); ?></span>
								<?php else : ?>
									<span class="plathix-system-info__value--neutral"><?php echo esc_html( $row['value'] ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
