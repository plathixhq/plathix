<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\RequestContext;
use Plathix\Infrastructure\Cache;
use Plathix\Loader;

final class Assets
{
	private SidebarScreenResolver $screen_resolver;
	private SidebarRuntimeConfigBuilder $config_builder;
	private AdminUiEnqueueService $admin_ui;

	/** Последний инстанс — шов PRO-обвязки edit-экранов (CTAN-303, паритет RestController::latest). */
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
			$this->screen_resolver,
		);
		$this->admin_ui = new AdminUiEnqueueService();
		if ( $this->loader ) {
			$this->loader->add_action( 'admin_enqueue_scripts', $this, 'register_escape_shared' );
			$this->loader->add_action( 'wp_enqueue_scripts', $this, 'register_escape_shared' );
			$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue' );
			$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend' );
			$this->loader->add_action( 'wp_enqueue_media', $this, 'enqueue_sidebar_for_media_modal' );
			$this->loader->add_filter( 'admin_body_class', $this, 'filter_admin_body_class' );
			$this->loader->add_action( 'in_admin_header', $this, 'render_static_shell' );
			$this->loader->add_action( 'admin_head', $this, 'output_cls_script', 1 );
		}
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_escape_shared' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_escape_shared' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_transport_shared' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_transport_shared' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_sidebar_for_media_modal' ] );
		add_filter( 'admin_body_class', [ $this, 'filter_admin_body_class' ] );
		add_action( 'in_admin_header', [ $this, 'render_static_shell' ] );
		add_action( 'admin_head', [ $this, 'output_cls_script' ], 1 );
	}

	/**
	 * Регистрирует (не enqueue) window.PlathixEscape.escapeHtml/escapeAttr как отдельный
	 * WP script handle ([internal], [internal]). Безусловная
	 * регистрация — не завязана на screen_resolver: PRO-потребители (например
	 * lightbox.js) работают на страницах, где Free sidebar не выводится вовсе (публичная
	 * галерея). wp_register_script не грузит файл в браузер — только делает хендл
	 * доступным для чужого wp_enqueue_script(...,['plathix-escape-shared']).
	 */
	public function register_escape_shared(): void {
		$asset = $this->get_asset( 'lib/escape-shared' );
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

	/**
	 * Регистрирует (не enqueue) window.PlathixTransport.restRequest/postType/parseJson/
	 * refreshNonce как отдельный WP script handle ([internal], [internal]).
	 * Тот же паттерн, что register_escape_shared() выше — безусловная регистрация,
	 * не завязана на screen_resolver: PRO-потребители (zip/folder-info/folder-upload)
	 * работают на страницах медиатеки, где Free sidebar уже загружен, но регистрация
	 * handle должна быть безусловной, чтобы PRO мог зависеть от неё предсказуемо.
	 */
	public function register_transport_shared(): void {
		$asset = $this->get_asset( 'lib/transport-shared' );
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
		$this->admin_ui->enqueue_for_hook( $hook );

		if ( $this->admin_ui->is_plathix_settings_page( $hook ) ) {
			return;
		}

		$ctx = $this->screen_resolver->resolve( $hook );
		if ( null === $ctx || ! RequestContext::is_active() ) {
			return;
		}

		$raw_post_type = RequestContext::get_post_type();
		$post_type     = $ctx['screen_context'] === 'upload' ? 'attachment' : $raw_post_type;

		$this->enqueue_sidebar_assets( $ctx, $post_type );
	}

	public function enqueue_frontend(): void {
		$ctx = $this->screen_resolver->resolve_frontend();
		if ( null === $ctx ) {
			return;
		}

		$this->enqueue_sidebar_assets( $ctx, 'attachment' );
	}

	/**
	 * Доставка sidebar в media picker (modal) через нативный WP-хук `wp_enqueue_media`.
	 *
	 * `wp_enqueue_media` (do_action в конце core-функции wp_enqueue_media()) файрится в
	 * ЛЮБОМ контексте, где поднимается media picker — upload.php grid, post.php, и все page
	 * builders (Elementor, Beaver, Bricks, Brizy, Divi): каждый вызывает wp_enqueue_media().
	 * Один нативный хук покрывает все билдеры платформо-независимо — без вендорной привязки к
	 * Elementor. Тот же паттерн, что у соседнего модуля replace-media (AttachmentReplaceUi).
	 *
	 * Idempotent-guard: на upload.php admin_enqueue_scripts (prio 10) уже отдал СТАТИЧНЫЙ
	 * sidebar раньше, чем grid-режим позовёт wp_enqueue_media() — guard не даёт второго
	 * (modal) энкьюива поверх статичного. В builder-контекстах статик не грузится, guard
	 * пропускает, sidebar едет как modal.
	 */
	public function enqueue_sidebar_for_media_modal(): void {
		if ( wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		// CTAN-201: проверка «attachment включён» удалена — медиатека безусловный Free-инвариант.

		$ctx = [
			'screen_context'  => 'upload',
			'screen_kind'     => 'modal',
			'media_mode'      => 'grid',
			'filter_strategy' => 'media-frame',
		];

		$this->enqueue_sidebar_assets( $ctx, 'attachment' );
	}

	/**
	 * @param array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string} $ctx
	 */
	/**
	 * CTAN-303 ([internal]): публичная обвязка доставки сайдбар-поверхности.
	 * PRO вызывает её на СВОИХ экранах (edit.php платных типов) со своим ctx — Free-резолвер
	 * этих экранов не знает by design; дублирование enqueue-позиций в PRO запрещено (р.3).
	 * $ctx — форма SidebarScreenResolver::resolve(): screen_context/screen_kind/media_mode/
	 * filter_strategy.
	 *
	 * @param array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string} $ctx
	 */
	public function enqueue_sidebar_assets(array $ctx, string $post_type): void {
		$screen_kind = $ctx['screen_kind'];

		$asset      = $this->get_asset( 'sidebar' );
		$script_url = PLATHIX_ASSETS_URL . 'js/sidebar.js';
		$style_path = PLATHIX_ASSETS_PATH . 'css/sidebar.css';
		$style_url  = PLATHIX_ASSETS_URL . 'css/sidebar.css';
		$deps       = array_values( array_unique( (array) ( $asset['dependencies'] ?? [] ) ) );

		if ( $screen_kind === 'modal' ) {
			// wp_enqueue_media() поднимает wp.media frame (media-editor/media-views) —
			// нативно и во всех контекстах (upload.php, post.php, page builders). sidebar.js
			// слушает `wp.media open` событие, ему не нужна PHP-зависимость от media-скрипта.
			//
			// НЕ добавляем 'media' в deps: handle 'media' = /wp-admin/js/media.js (медиа-СПИСОК
			// upload.php), а не media picker. Он полноценно выводится лишь на admin media-screens;
			// в builder-контекстах WP_Dependencies::all_deps() его не резолвит и МОЛЧА выбрасывает
			// sidebar.js из вывода ([internal]: в Elementor css грузился, js — нет). Как конкуренты
			// (filebird/happyfiles/bwdmfmx): wp_enqueue_media() + deps без 'media'.
			if ( function_exists( 'wp_enqueue_media' ) ) {
				wp_enqueue_media();
			}
		}

		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		// [internal] ([internal]): стратегия загрузки объявляется нативным
		// $args-массивом того же вызова (WP >= 6.3, наш минимум — 7.0), а не отдельным
		// wp_script_add_data. Отдельную строку можно забыть — ключ внутри обязательного
		// вызова забыть негде; ownership при этом не меняется (владелец хендла сам
		// декларирует свою стратегию, инвариант [internal] сохранён).
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
			wp_add_inline_style( 'plathix-sidebar', $this->root_css_variables() );
		}

		$data = $this->config_builder->build( $ctx, $post_type );
		// storeKey ([internal]): Alpine store-ключ как явный runtime-контракт для PRO —
		// консьюмеры читают window.Plathix.storeKey вместо хардкода литерала 'plathix'.
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
	private function get_asset(string $name): array {
		$file = PLATHIX_ASSETS_PATH . "js/{$name}.asset.php";
		if ( file_exists( $file ) ) {
			$asset = require $file;
			if ( is_array( $asset ) ) {
				return $asset;
			}
		}

		return [
			'version'      => PLATHIX_VERSION,
			'dependencies' => [],
		];
	}

	public function output_cls_script(): void {
		if ( ! $this->screen_resolver->should_render_static_shell() ) {
			return;
		}

		// CTAN-402: Free-предикат пропускает только медиатеку — тип здесь всегда attachment
		// (ветка выбора по экранам записей мертва после attachment-native чистки; PRO печатает
		// CLS для своих экранов через output_cls_script_for()).
		self::output_cls_script_for( 'attachment' );
	}

	public function filter_admin_body_class(string $classes): string {
		if ( ! $this->screen_resolver->should_render_static_shell() ) {
			return $classes;
		}

		return self::body_classes_with_shell( $classes );
	}

	public function render_static_shell(): void {
		if ( ! $this->screen_resolver->should_render_static_shell() ) {
			return;
		}

		self::render_static_shell_now();
	}

	// --- CTAN-303: force-двойники обвязки для PRO-экранов (Free-предикат их не знает) ---

	/** Классы body с сайдбар-shell (обвязка PRO admin_body_class). */
	public static function body_classes_with_shell(string $classes): string {
		$classes = trim( $classes );
		$extra   = 'plathix-body-sidebar plathix-sidebar-shell';

		return $classes === '' ? $extra : $classes . ' ' . $extra;
	}

	/** Статичная оболочка сайдбара (обвязка PRO in_admin_header). */
	public static function render_static_shell_now(): void {
		echo '<div id="plathix-sidebar-root" aria-hidden="true"></div>';
	}

	/** Anti-CLS inline-script для заданного типа (обвязка PRO admin_head prio 1). */
	public static function output_cls_script_for(string $post_type): void {
		$storage_key = wp_json_encode( 'plathix_sidebar_' . sanitize_key( $post_type ) );
		$js           = '!function(){try{var s=JSON.parse(localStorage.getItem(' . $storage_key . ')||"{}"),w=+s.width||320;document.documentElement.style.setProperty("--plathix-sidebar-width-dynamic",w+"px")}catch(e){}}();';
		self::print_inline_script_now( 'plathix-cls-inline', $js );
	}

	/**
	 * WP.org review round 1 ([internal]): печатает inline JS СИНХРОННО, в текущей точке
	 * потока HTML — не в head/footer batch. `wp_add_inline_script(..., 'before')` сам по
	 * себе откладывает вывод до `wp_print_scripts()` того handle; для handle без своего
	 * src и без явного batch-момента (наш случай — anti-FOUC точки, которые должны
	 * исполниться ДО рендера сайдбара/rail, вне обычного head/footer прохода) нужен ручной
	 * немедленный `wp_print_scripts($handle)`. WP core API, не собственный транспорт —
	 * `wp_print_scripts()` печатает handle сразу и трекает его в `WP_Scripts::$done`,
	 * повторный вызов того же handle idempotent (WP core не печатает дважды). Публичный —
	 * вызывается также из `views/admin-layout.php` (rail anti-FOUC, тот же timing-класс).
	 */
	public static function print_inline_script_now(string $handle, string $js): void {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, '', [], null );
		}
		wp_add_inline_script( $handle, $js, 'before' );
		wp_print_scripts( $handle );
	}

	/** @return string[] */
	public static function get_builder_image_sizes(): array {
		return self::get_image_sizes_cached();
	}

	/** @return string[] */
	private static function get_image_sizes_cached(): array {
		static $sizes = null;
		if ( $sizes === null ) {
			$sizes = array_values( array_unique( array_merge( [ 'thumbnail', 'medium', 'large', 'full' ], array_values( get_intermediate_image_sizes() ) ) ) );
		}
		return $sizes;
	}

	private function root_css_variables(): string {
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
