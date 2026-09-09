<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\RequestContext;
use Plathix\Http\Authorization;
use Plathix\Infrastructure\Cache;
use Plathix\Loader;

final class Assets
{
	private SidebarScreenResolver $screen_resolver;
	private SidebarRuntimeConfigBuilder $config_builder;
	private AdminUiEnqueueService $admin_ui;

	private static ?self $latest_instance = null;

	public static function latest(): ?self {
		return self::$latest_instance;
	}

	public function __construct(
		private readonly ?Loader $loader = null
	) {
		self::$latest_instance = $this;
		$repository            = new FolderRepository();
		$folders               = new FolderCountService( $repository, Cache::make() );
		$assembler             = new SidebarBootstrapAssembler( $folders, $repository );
		$i18n_builder          = new SidebarI18nBuilder();
		$this->screen_resolver = new SidebarScreenResolver();
		$this->config_builder  = new SidebarRuntimeConfigBuilder(
			$folders,
			$assembler,
			$i18n_builder,
		);
		$this->admin_ui = new AdminUiEnqueueService();
		if ( $this->loader ) {
			$this->loader->addAction( 'admin_enqueue_scripts', $this, 'registerEscapeShared' );
			$this->loader->addAction( 'wp_enqueue_scripts', $this, 'registerEscapeShared' );
			$this->loader->addAction( 'admin_enqueue_scripts', $this, 'enqueue' );
			$this->loader->addAction( 'wp_enqueue_scripts', $this, 'enqueueFrontend' );
			$this->loader->addAction( 'wp_enqueue_media', $this, 'enqueueSidebarForMediaModal' );
			$this->loader->addFilter( 'admin_body_class', $this, 'filterAdminBodyClass' );
			$this->loader->addAction( 'in_admin_header', $this, 'renderStaticShell' );
			$this->loader->addAction( 'admin_head', $this, 'outputClsScript', 1 );
		}
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'registerEscapeShared' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'registerEscapeShared' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'registerTransportShared' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'registerTransportShared' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueFrontend' ] );
		add_action( 'wp_enqueue_media', [ $this, 'enqueueSidebarForMediaModal' ] );
		add_filter( 'admin_body_class', [ $this, 'filterAdminBodyClass' ] );
		add_action( 'in_admin_header', [ $this, 'renderStaticShell' ] );
		add_action( 'admin_head', [ $this, 'outputClsScript' ], 1 );
	}

	public function registerEscapeShared(): void {
		$asset = $this->getAsset( 'lib/escape-shared' );
		wp_register_script(
			'plathix-escape-shared',
			PLATHIX_ASSETS_URL . 'js/lib/escape-shared.js',
			[],
			$asset['version'] ?? PLATHIX_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}

	public function registerTransportShared(): void {
		$asset = $this->getAsset( 'lib/transport-shared' );
		wp_register_script(
			'plathix-transport-shared',
			PLATHIX_ASSETS_URL . 'js/lib/transport-shared.js',
			[],
			$asset['version'] ?? PLATHIX_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}

	public function enqueue(string $hook): void {
		$this->admin_ui->enqueueForHook( $hook );

		if ( $this->admin_ui->isPlathixSettingsPage( $hook ) ) {
			return;
		}

		$ctx = $this->screen_resolver->resolve( $hook );
		if ( null === $ctx || ! RequestContext::isActive() ) {
			return;
		}

		$raw_post_type = RequestContext::getPostType();
		$post_type     = $ctx['screen_context'] === 'upload' ? 'attachment' : $raw_post_type;

		$this->enqueueSidebarAssets( $ctx, $post_type );
	}

	public function enqueueFrontend(): void {
		$ctx = $this->screen_resolver->resolveFrontend();
		if ( null === $ctx ) {
			return;
		}

		$this->enqueueSidebarAssets( $ctx, 'attachment' );
	}

	public function enqueueSidebarForMediaModal(): void {
		if ( wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		if ( ! Authorization::capability( 'view', 'attachment' ) ) {
			return;
		}


		$ctx = [
			'screen_context'  => 'upload',
			'screen_kind'     => 'modal',
			'media_mode'      => 'grid',
			'filter_strategy' => 'media-frame',
		];

		$this->enqueueSidebarAssets( $ctx, 'attachment' );
	}

	/**
	 * @param array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string} $ctx
	 */
	/**
	 * @param array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string} $ctx
	 */

	public function enqueueSidebarAssets(array $ctx, string $post_type): void {
		$screen_kind = $ctx['screen_kind'];

		$asset      = $this->getAsset( 'sidebar' );
		$script_url = PLATHIX_ASSETS_URL . 'js/sidebar.js';
		$style_path = PLATHIX_ASSETS_PATH . 'css/sidebar.css';
		$style_url  = PLATHIX_ASSETS_URL . 'css/sidebar.css';
		$deps       = array_values( array_unique( (array) ( $asset['dependencies'] ?? [] ) ) );

		if ( $screen_kind === 'modal' ) {

			if ( function_exists( 'wp_enqueue_media' ) ) {
				wp_enqueue_media();
			}
		}

		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		wp_enqueue_script(
			'plathix-sidebar',
			$script_url,
			array_unique( $deps ),
			$version,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( 'plathix-sidebar', $style_url, [], $version );
			wp_add_inline_style( 'plathix-sidebar', $this->rootCssVariables() );
		}

		$data = $this->config_builder->build( $ctx, $post_type );

		$data['storeKey'] = 'plathix';
		$data = apply_filters( 'plathix/assets/js_data', $data );

		wp_localize_script( 'plathix-sidebar', 'Plathix', $data );
		// PX is a deprecated alias for Plathix — set at runtime in sidebar JS bootstrap.

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'plathix-sidebar', 'plathix', PLATHIX_PATH . 'languages' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getAsset(string $name): array {
		return \Plathix\Infrastructure\AssetManifest::read( "js/{$name}.asset.php" );
	}

	public function outputClsScript(): void {
		if ( ! $this->screen_resolver->shouldRenderStaticShell() ) {
			return;
		}

		self::outputClsScriptFor( 'attachment' );
	}

	public function filterAdminBodyClass(string $classes): string {
		if ( ! $this->screen_resolver->shouldRenderStaticShell() ) {
			return $classes;
		}

		return self::bodyClassesWithShell( $classes );
	}

	public function renderStaticShell(): void {
		if ( ! $this->screen_resolver->shouldRenderStaticShell() ) {
			return;
		}

		self::renderStaticShellNow();
	}

	public static function bodyClassesWithShell(string $classes): string {
		$classes = trim( $classes );
		$extra   = 'plathix-body-sidebar plathix-sidebar-shell';

		return $classes === '' ? $extra : $classes . ' ' . $extra;
	}

	public static function renderStaticShellNow(): void {
		echo '<div id="plathix-sidebar-root" aria-hidden="true"></div>';
	}

	public static function outputClsScriptFor(string $post_type): void {
		$storageKey = wp_json_encode( 'plathix_sidebar_' . sanitize_key( $post_type ) );
		$js           = '!function(){try{var s=JSON.parse(localStorage.getItem(' . $storageKey . ')||"{}"),w=+s.width||320;document.documentElement.style.setProperty("--plathix-sidebar-width-dynamic",w+"px")}catch(e){}}();';
		self::printInlineScriptNow( 'plathix-cls-inline', $js );
	}

	public static function printInlineScriptNow(string $handle, string $js): void {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, '', [], null );
		}
		wp_add_inline_script( $handle, $js, 'before' );
		wp_print_scripts( $handle );
	}

	/** @return string[] */
	public static function getBuilderImageSizes(): array {
		return self::getImageSizesCached();
	}

	/** @return string[] */
	private static function getImageSizesCached(): array {
		static $sizes = null;
		if ( $sizes === null ) {
			$sizes = array_values( array_unique( array_merge( [ 'thumbnail', 'medium', 'large', 'full' ], array_values( get_intermediate_image_sizes() ) ) ) );
		}
		return $sizes;
	}

	private function rootCssVariables(): string {
		$sidebar_z  = (int) apply_filters( 'plathix/ui/z_index_sidebar', 100 );
		$overlay_z  = (int) apply_filters( 'plathix/ui/z_index_overlay', 9000 );
		$lightbox_z = (int) apply_filters( 'plathix/ui/z_index_lightbox', 160001 );
		$toast_z    = (int) apply_filters( 'plathix/ui/z_index_toast', 200000 );

		$z_vars = sprintf(
			':root{--plathix-sidebar-z:%1$d;--plathix-overlay-z:%2$d;--plathix-lightbox-z:%3$d;--plathix-toast-z:%4$d;}',
			$sidebar_z,
			$overlay_z,
			$lightbox_z,
			$toast_z
		);

		/**
		 * Override sidebar design tokens.
		 *
		 * Return an associative array of CSS custom property name => value.
		 * Property names must start with --plathix-.
		 * Example:
		 *   add_filter( 'plathix/sidebar/css_vars', function( $vars ) {
		 *       $vars['--plathix-accent'] = '#e44';
		 *       return $vars;
		 *   } );
		 *
		 * @param array<string,string> $vars
		 */
		$css_vars = (array) apply_filters( 'plathix/sidebar/css_vars', [] );

		if ( ! empty( $css_vars ) ) {
			$declarations = '';
			foreach ( $css_vars as $prop => $value ) {
				$prop  = sanitize_text_field( (string) $prop );
				$value = sanitize_text_field( (string) $value );
				if ( str_starts_with( $prop, '--plathix-' ) && $value !== '' ) {
					$declarations .= $prop . ':' . $value . ';';
				}
			}
			if ( $declarations !== '' ) {
				$z_vars .= '.plathix-sidebar{' . $declarations . '}';
			}
		}

		return $z_vars;
	}
}
