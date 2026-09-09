<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard;

use Plathix\Core\AdminLayout;
use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Keys;
use Plathix\Infrastructure\Logger;
use Plathix\Modules\Dashboard\Widgets\UploadsWidget;
use Plathix\Modules\Dashboard\Widgets\MimeTypesWidget;
use Plathix\Modules\Dashboard\Widgets\OrphanedFilesWidget;
use Plathix\Modules\Dashboard\Widgets\FoldersWidget;
use Plathix\Modules\Dashboard\Widgets\MigrationBannerWidget;
use Plathix\Modules\Dashboard\Widgets\PresetWidget;
use Plathix\Modules\Dashboard\Widgets\StatusBarWidget;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

class HomeDashboardPage
{
	public const PAGE_SLUG = 'plathix';
	public const DISMISS_META_KEY = 'plathix_onboarding_dismissed';
	public const MIGRATION_DISMISS_META_KEY = 'plathix_migration_dismissed';

	public static function blogScopedMetaKey(string $base): string {
		return $base . Keys::blogSuffix();
	}

	private const MIGRATION_SOURCES = [ 'filebird', 'wpmediafolder', 'realmedialib', 'happyfiles', 'wickedfolders' ];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ], 9 );
		add_action( 'wp_ajax_plathix_dismiss_onboarding', [ $this, 'handleDismiss' ] );
		add_action( 'wp_ajax_plathix_dismiss_migration', [ $this, 'handleDismissMigration' ] );

		add_filter( 'plathix/admin/rootSlug', static fn (): string => self::PAGE_SLUG );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'Home', 'plathix' ),
				'isPlathixPage' => true,
				'is_root'         => true,
				'section'         => 'main',
				'order'           => 10,
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
			];
			return $pages;
		} );
	}

	public function addPage(): void {
		add_menu_page(
			__( 'Plathix', 'plathix' ),
			__( 'Plathix', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ],
			'dashicons-category',
			79
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Plathix', 'plathix' ),
			__( 'Home', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function handleDismiss(): void {
		check_ajax_referer( 'plathix_dismiss_onboarding', 'nonce' );

		AjaxGuard::requireCap( AccessLevel::Full, 'manage_options' );

		$card_id = isset( $_POST['card_id'] ) ? sanitize_key( wp_unslash( $_POST['card_id'] ) ) : '';
		if ( '' === $card_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing card id.', 'plathix' ) ], 400 );
		}

		$user_id  = get_current_user_id();
		$meta_key = self::blogScopedMetaKey( self::DISMISS_META_KEY );

		$lock_name = Keys::lock( 'dismiss_onboarding_' . $user_id );
		$acquired  = DbAdvisoryLock::acquire( $lock_name, 3 );

		try {
			$dismissed = get_user_meta( $user_id, $meta_key, true );
			$dismissed = is_array( $dismissed ) ? $dismissed : [];

			if ( ! in_array( $card_id, $dismissed, true ) ) {
				$dismissed[] = $card_id;

				$written = update_user_meta( $user_id, $meta_key, array_values( array_unique( $dismissed ) ) );

				if ( ! $written ) {
					Logger::error( 'dashboard_dismiss_write_failed', [ 'user_id' => $user_id, 'meta_key' => $meta_key ] );
				}
			}
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release( $lock_name );
			}
		}

		wp_send_json_success();
	}

	public function handleDismissMigration(): void {
		check_ajax_referer( 'plathix_dismiss_migration', 'nonce' );

		AjaxGuard::requireCap( AccessLevel::Full, 'manage_options' );

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! in_array( $source, self::MIGRATION_SOURCES, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown source.', 'plathix' ) ], 400 );
		}

		$user_id  = get_current_user_id();
		$meta_key = self::blogScopedMetaKey( self::MIGRATION_DISMISS_META_KEY );

		$lock_name = Keys::lock( 'dismiss_migration_' . $user_id );
		$acquired  = DbAdvisoryLock::acquire( $lock_name, 3 );

		try {
			$dismissed = get_user_meta( $user_id, $meta_key, true );
			$dismissed = is_array( $dismissed ) ? $dismissed : [];

			if ( ! in_array( $source, $dismissed, true ) ) {
				$dismissed[] = $source;

				$written = update_user_meta( $user_id, $meta_key, array_values( array_unique( $dismissed ) ) );

				if ( ! $written ) {
					Logger::error( 'dashboard_dismiss_write_failed', [ 'user_id' => $user_id, 'meta_key' => $meta_key ] );
				}
			}
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release( $lock_name );
			}
		}

		wp_send_json_success();
	}

	/**
	 * @return array<int, array{slug:string,zone:string,order:int,render:callable}>
	 */

	private function widgets(): array {
		$host = [
			[ 'slug' => 'status-bar',       'zone' => 'pre-grid',          'order' => 10, 'render' => static function (array $data): void {
				( new StatusBarWidget() )->render( $data );
			} ],
			[ 'slug' => 'migration-banner', 'zone' => 'pre-grid',          'order' => 20, 'render' => static function (array $data): void {
				( new MigrationBannerWidget() )->render( $data );
			} ],
			[ 'slug' => 'uploads',          'zone' => 'row-analytics-top', 'order' => 10, 'render' => static function (array $data): void {
				( new UploadsWidget() )->render( $data );
			} ],
			[ 'slug' => 'mime-types',       'zone' => 'row-analytics-top', 'order' => 20, 'render' => static function (array $data): void {
				( new MimeTypesWidget() )->render( $data );
			} ],
			[ 'slug' => 'orphaned-files',   'zone' => 'row-analytics-top', 'order' => 30, 'render' => static function (array $data): void {
				( new OrphanedFilesWidget() )->render( $data );
			} ],
			[ 'slug' => 'folders',          'zone' => 'row-top',           'order' => 10, 'render' => static function (array $data): void {
				( new FoldersWidget() )->render( $data );
			} ],
			[ 'slug' => 'preset',           'zone' => 'row-top',           'order' => 20, 'render' => static function (array $data): void {
				( new PresetWidget() )->render( $data );
			} ],

		];

		/**
		 * @param array<int, array{slug:string,zone:string,order:int,render:callable}> $widgets
		 */

		$widgets = apply_filters( 'plathix/dashboard/widgets', $host );

		return is_array( $widgets ) ? array_values( $widgets ) : $host;
	}

	/**
	 * @return array<string, array<int, string>>
	 */

	public function widgetZones(): array {
		$out = [];
		foreach ( $this->widgetsByZone() as $zone => $list ) {
			$out[ $zone ] = array_map( static fn (array $w): string => (string) $w['slug'], $list );
		}
		return $out;
	}

	public function render(): void {
		AdminLayout::renderPage( self::PAGE_SLUG, function (): void {
			if ( ! AccessResolver::currentUserIsFullAdmin() ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}

			$data = ( new HomeDashboardData() )->collect();

			$dash_asset   = \Plathix\Infrastructure\AssetManifest::read( 'js/admin-ui/dashboard.asset.php' );
			$dash_version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : $dash_asset['version'];

			if ( file_exists( PLATHIX_ASSETS_PATH . 'js/admin-ui/dashboard.js' ) ) {
				wp_enqueue_script(
					'plathix-dashboard-ui',
					PLATHIX_ASSETS_URL . 'js/admin-ui/dashboard.js',
					$dash_asset['dependencies'] ?? [],
					$dash_version,
					true
				);
				wp_localize_script( 'plathix-dashboard-ui', 'PlathixDashboard', [
					'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
					'dismissNonce'          => wp_create_nonce( 'plathix_dismiss_onboarding' ),
					'migrationDismissNonce' => wp_create_nonce( 'plathix_dismiss_migration' ),
				] );
				wp_set_script_translations( 'plathix-dashboard-ui', 'plathix', PLATHIX_PATH . 'languages' );
			}

			if ( file_exists( PLATHIX_ASSETS_PATH . 'css/admin-ui/dashboard.css' ) ) {
				wp_enqueue_style(
					'plathix-dashboard',
					PLATHIX_ASSETS_URL . 'css/admin-ui/dashboard.css',
					[ 'plathix-admin-ui' ],
					$dash_version
				);
			}

			$zones = $this->widgetsByZone();
			?>
		<div class="plathix-page">
			<?php

			?>
			<h1 class="screen-reader-text"><?php esc_html_e( 'Plathix Dashboard', 'plathix' ); ?></h1>
			<?php

			$this->renderZone( $zones, 'pre-grid', $data );

			do_action( 'plathix/dashboard/renderOnboarding', $data );
			$this->renderMainGrid( $zones, $data );

			do_action( 'plathix/onboarding/render_modal' );
			?>
		</div>
			<?php
		} );
	}

	/**
	 * @return array<string, array<int, array{slug:string,zone:string,order:int,render:callable}>>
	 */

	private function widgetsByZone(): array {
		$zones = [];
		foreach ( $this->widgets() as $widget ) {
			$zone = (string) ( $widget['zone'] ?? 'content' );
			$zones[ $zone ][] = $widget;
		}
		foreach ( $zones as &$list ) {
			usort( $list, static fn (array $a, array $b): int => ( (int) $a['order'] ) <=> ( (int) $b['order'] ) );
		}
		unset( $list );
		return $zones;
	}

	/**
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */

	private function renderZone(array $zones, string $zone, array $data): void {
		foreach ( $zones[ $zone ] ?? [] as $widget ) {
			( $widget['render'] )( $data );
		}
	}

	/**
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */

	private function renderRow(array $zones, string $zone, string $suffix, array $data): void {
		if ( empty( $zones[ $zone ] ) ) {
			return;
		}
		echo '<div class="plathix-home__row plathix-home__row--' . esc_attr( $suffix ) . '">';
		$this->renderZone( $zones, $zone, $data );
		echo '</div>';
	}

	/**
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */
	private function renderMainGrid(array $zones, array $data): void {
		$this->renderRow( $zones, 'row-analytics-top', 'analytics-top', $data );
		$this->renderRow( $zones, 'row-top', 'top', $data );

		$this->renderZone( $zones, 'content', $data );
		$this->renderRow( $zones, 'row-bottom', 'bottom', $data );

		$known = [ 'pre-grid', 'row-analytics-top', 'row-top', 'content', 'row-bottom' ];
		foreach ( $zones as $zone => $widgets ) {
			if ( ! in_array( $zone, $known, true ) ) {
				$this->renderZone( $zones, $zone, $data );
			}
		}

		$docs_url = (string) apply_filters( 'plathix/docs/page_url', '' );
		if ( $docs_url !== '' ) :
			?>
			<div class="plathix-docs-nudge">
				<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14 1H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM4 13a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h9v11H4z"/></svg>
				<span><?php esc_html_e( 'New to Plathix?', 'plathix' ); ?></span>
				<a href="<?php echo esc_url( $docs_url ); ?>">
					<?php esc_html_e( 'Read the getting started guide →', 'plathix' ); ?>
				</a>
			</div>
			<?php
		endif;
	}
}
