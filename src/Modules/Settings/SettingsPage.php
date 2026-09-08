<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Core\TaxonomyResolver;
use Plathix\Loader;
use Plathix\User\AccessResolver;

class SettingsPage
{
	public const PAGE_SLUG = 'plathix-settings';
	public const OPTION_GROUP = 'plathix_settings_group';

	private SettingsSaveHandler $save_handler;

	public function __construct(
		private readonly SettingsView $view = new SettingsView(),
		private readonly ?Loader $loader = null
	) {
		$this->save_handler = new SettingsSaveHandler(
			[ $this, 'canManageSettings' ],
			[ $this, 'settingsUrl' ]
		);
		if ( $this->loader ) {
			$this->loader->addAction( 'admin_menu', $this, 'addPage' );
			$this->loader->addAction( 'admin_init', $this, 'registerSettings' );
			$this->loader->addAction( 'admin_enqueue_scripts', $this, 'enqueueScripts' );
			$this->loader->addAction( 'admin_post_plathix_export', $this->save_handler, 'handleExport' );
			$this->loader->addAction( 'admin_post_plathix_export_preset', $this->save_handler, 'handleExportPreset' );
			$this->loader->addAction( 'admin_post_plathix_import_json', $this->save_handler, 'handleImportJson' );
			$this->loader->addFilter( 'pre_update_option', $this, 'guardOptionUpdates', 10, 3 );
			$this->loader->addFilter( 'wp_redirect', $this, 'preserveSettingsTab', 10, 2 );

			$this->loader->addAction( 'plathix/settings/save', $this->save_handler, 'registerSaveHandler', 10, 2 );

			$this->loader->addAction( 'plathix/settings/register_tab', $this, 'registerSaveTabHandler', 10, 2 );
		}

		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'Settings', 'plathix' ),
				'isPlathixPage' => true,
				'section'         => 'main',
				'order'           => 20,
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
			];
			return $pages;
		} );
	}

	public function addPage(): void {
		if ( ! $this->canManageSettings() ) {
			return;
		}

		add_submenu_page(
			(string) apply_filters( 'plathix/admin/rootSlug', 'plathix' ),
			__( 'Settings', 'plathix' ),
			__( 'Settings', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this->view, 'render' ]
		);
	}

	public function enqueueScripts(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'plathix_page_' . self::PAGE_SLUG ) {
			return;
		}
		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/admin-ui/settings.asset.php' );
		wp_enqueue_script(
			'plathix-settings-ui',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/admin-ui/settings.js' : '',
			$asset['dependencies'] ?? [],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'plathix-settings-ui', 'plathix', PLATHIX_PATH . 'languages' );

		if ( defined( 'PLATHIX_PATH' ) && file_exists( PLATHIX_PATH . 'assets/css/admin-ui/settings.css' ) ) {
			wp_enqueue_style(
				'plathix-settings-ui',
				defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/admin-ui/settings.css' : '',
				[ 'plathix-admin-ui' ],
				$asset['version']
			);
		}
	}

	public function registerSettings(): void {

		do_action( 'plathix/settings/save', 'plathix_default_folder_id', static function (mixed $raw = null): bool {

			$raw = wp_unslash( $raw );
			if ( $raw === null || $raw === '' ) {
				return true;
			}

			return \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_default_folder_id', absint( $raw ) );
		} );

		do_action( 'plathix/settings/save', 'plathix_infinite_scroll', static function (mixed $raw = null): bool {
			return \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_infinite_scroll', (bool) wp_unslash( $raw ?? false ) );
		} );

		do_action( 'plathix/settings/save', 'plathix_bulk_safe_mode', static function (mixed $raw = null): bool {
			return \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_bulk_safe_mode', (bool) wp_unslash( $raw ?? false ) );
		} );

		add_settings_section(
			'plathix_general',
			__( 'General', 'plathix' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'plathix_infinite_scroll',
			__( 'Media Grid', 'plathix' ),
			[ $this, 'renderInfiniteScroll' ],
			self::PAGE_SLUG,
			'plathix_general'
		);

		/**
		 * @param string $option_group
		 */

		do_action( 'plathix/settings/register', self::OPTION_GROUP );

		/**
		 * @param array<int, string> $option_names
		 */

		$general_options = apply_filters( 'plathix/settings/option_tab_map', [
			'plathix_default_folder_id',
			'plathix_infinite_scroll',
			'plathix_bulk_safe_mode',
		] );
		$this->save_handler->registerTabHandler( 'general', is_array( $general_options ) ? $general_options : [] );
	}


	public function renderInfiniteScroll(): void {
		$enabled = (bool) get_option( 'plathix_infinite_scroll', false );
		?>
		<label>
			<input type="checkbox" name="plathix_infinite_scroll" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Enable infinite scroll in media library grid', 'plathix' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Automatically loads more files when scrolling down in the media grid and media picker (Gutenberg, Elementor, Classic editor).', 'plathix' ); ?>
		</p>
		<?php
	}

	public function preserveSettingsTab(string $location, int $status): string {
		if ( ! str_contains( $location, 'page=' . self::PAGE_SLUG ) ) {
			return $location;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only redirect param, no data modified
		$tab = sanitize_key( (string) ( $_POST['_plathix_redirect_tab'] ?? '' ) );

		if ( $tab !== '' && in_array( $tab, $this->view->tabSlugs(), true ) ) {
			$location = add_query_arg( 'tab', $tab, $location );
		}

		return $location;
	}

	public function settingsUrl(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @param array<int, string> $option_names
	 */

	public function registerSaveTabHandler(string $tab_slug, array $option_names): void {
		$this->save_handler->registerTabHandler( $tab_slug, $option_names );
	}

	private const OWNED_OPTIONS = [
		'plathix_default_folder_id',
		'plathix_infinite_scroll',
		'plathix_bulk_safe_mode',
	];

	public function guardOptionUpdates(mixed $value, mixed $option, mixed $old_value): mixed {
		$is_boot_recovered_flag = 'plathix_boot_recovered_lazily' === $option;
		if ( ! is_string( $option ) || ( ! in_array( $option, self::OWNED_OPTIONS, true ) && ! $is_boot_recovered_flag ) ) {
			return $value;
		}

		if ( $this->canManageSettings() ) {
			return $value;
		}

		if ( 'plathix_boot_recovered_lazily' === $option ) {
			$int_value = is_scalar( $value ) ? (int) $value : null;
			if ( null !== $int_value && in_array( $int_value, [ 0, 1 ], true ) ) {
				return $int_value;
			}
		}

		return $old_value;
	}

	public function canManageSettings(): bool {
		return AccessResolver::currentUserIsFullAdmin();
	}
}
