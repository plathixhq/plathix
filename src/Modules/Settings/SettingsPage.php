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
			[ $this, 'can_manage_settings' ],
			[ $this, 'settings_url' ]
		);
		if ( $this->loader ) {
			$this->loader->add_action( 'admin_menu', $this, 'add_page' );
			$this->loader->add_action( 'admin_init', $this, 'register_settings' );
			$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_scripts' );
			$this->loader->add_action( 'admin_post_plathix_export', $this->save_handler, 'handle_export' );
			$this->loader->add_action( 'admin_post_plathix_export_preset', $this->save_handler, 'handle_export_preset' );
			$this->loader->add_action( 'admin_post_plathix_import_json', $this->save_handler, 'handle_import_json' );
			$this->loader->add_filter( 'pre_update_option', $this, 'guard_option_updates', 10, 3 );
			$this->loader->add_filter( 'wp_redirect', $this, 'preserve_settings_tab', 10, 2 );
			// [internal]: per-опция save-регистрация (модули, включая PRO, подписываются
			// сами через add_action на этот хук — SettingsPage не хранит имён их опций).
			$this->loader->add_action( 'plathix/settings/save', $this->save_handler, 'register_save_handler', 10, 2 );
			// [internal]: single-owner табы (svg/trash/access) регистрируют себя через
			// хук, не через прямой вызов SettingsSaveHandler — тот же decoupled паттерн, что
			// уже используют модули для plathix/settings/register/plathix/admin/settings_tabs.
			$this->loader->add_action( 'plathix/settings/register_tab', $this, 'register_save_tab_handler', 10, 2 );
		}

		// Самодекларация в реестр admin-меню ([internal]).
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'Settings', 'plathix' ),
				'is_plathix_page' => true,
				'section'         => 'main',
				'order'           => 20,
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
			];
			return $pages;
		} );
	}

	public function add_page(): void {
		if ( ! $this->can_manage_settings() ) {
			return;
		}

		add_submenu_page(
			(string) apply_filters( 'plathix/admin/root_slug', 'plathix' ),
			__( 'Settings', 'plathix' ),
			__( 'Settings', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this->view, 'render' ]
		);
	}

	public function enqueue_scripts(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'plathix_page_' . self::PAGE_SLUG ) {
			return;
		}
		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/admin-ui/settings.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];
		wp_enqueue_script(
			'plathix-settings-ui',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/admin-ui/settings.js' : '',
			(array) ( $asset['dependencies'] ?? [] ),
			$asset['version'],
			true
		);
		wp_set_script_translations( 'plathix-settings-ui', 'plathix', PLATHIX_PATH . 'languages' );

		// CSS страницы Settings co-located ([internal]): вынесен из общего admin-ui.css
		// в settings.css, грузится только здесь. Собирает специфику всех табов страницы
		// (General/SettingsView, Danger Zone/DataWipe, SVG/Svg) — владелец страница, не
		// отдельный таб, зеркало dashboard.css ([internal]: несколько виджетов, один файл).
		if ( defined( 'PLATHIX_PATH' ) && file_exists( PLATHIX_PATH . 'assets/css/admin-ui/settings.css' ) ) {
			wp_enqueue_style(
				'plathix-settings-ui',
				defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/admin-ui/settings.css' : '',
				[ 'plathix-admin-ui' ],
				$asset['version']
			);
		}
	}

	public function register_settings(): void {
		// CTAN-201: настройка выбора типов контента целиком принадлежит PRO (ContentTypes-модуль)
		// ([internal]): выбор доп-типов (post/page/CPT) — платная фича. Без PRO опция не в
		// whitelist Free → сабмит настроек её НЕ трогает (не затирает выбор). Медиабиблиотека
		// (attachment) — безусловный инвариант Free-рантайма, настройкой не управляется.

		// [internal]: plathix_role_access НЕ регистрируется в Free — per-role политика
		// применяется только в PRO (RolePolicy через plathix/user/access_level). Без регистрации
		// Free-форма не перезаписывает опцию; данные в БД сохраняются для подхвата PRO.

		// SVG-опции (plathix_svg_*) регистрирует Modules\Svg\SvgSettings по хуку
		// plathix/settings/register ([internal]/103) — хост их больше не держит.

		// [internal]: 3 host-опции General мигрированы с register_setting()/OPTION_GROUP
		// на изолированный plathix/settings/save ([internal]/#518) — устраняет саму возможность
		// class-of-bug "сохранение другого таба затирает эту опцию", не только текущие 2 живых
		// случая (plathix_infinite_scroll, plathix_bulk_safe_mode).
		// [internal]: регистрация через do_action(), не прямой вызов
		// $this->save_handler->register_save_handler() — единая сигнатура на все 6
		// регистраторов опций (Free+PRO), симметрично SvgSettings/TrashSettings/PRO-модулям.
		do_action( 'plathix/settings/save', 'plathix_default_folder_id', static function (mixed $raw = null): bool {
			// [internal]: отсутствующее/пустое значение означает «не менять», не «сбросить
			// в 0» — штатный <select> (SettingsView::render_default_upload_folder) всегда
			// шлёт непустое значение (минимум '0' "No folder"), но диспетчер вызывает этот
			// callback безусловно для каждой опции таба, включая нештатное отсутствие ключа.
			$raw = wp_unslash( $raw );
			if ( $raw === null || $raw === '' ) {
				return true;
			}
			update_option( 'plathix_default_folder_id', absint( $raw ) );
			return true;
		} );

		// Чекбокс: отсутствие ключа в форме означает «выключено», поэтому null → false.
		do_action( 'plathix/settings/save', 'plathix_infinite_scroll', static function (mixed $raw = null): bool {
			update_option( 'plathix_infinite_scroll', (bool) wp_unslash( $raw ?? false ) );
			return true;
		} );

		do_action( 'plathix/settings/save', 'plathix_bulk_safe_mode', static function (mixed $raw = null): bool {
			update_option( 'plathix_bulk_safe_mode', (bool) wp_unslash( $raw ?? false ) );
			return true;
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
			[ $this, 'render_infinite_scroll' ],
			self::PAGE_SLUG,
			'plathix_general'
		);

		/**
		 * Точка расширения: модули регистрируют свои register_setting в общую OPTION_GROUP
		 * страницы Settings ([internal]). Стреляет на admin_init (внутри
		 * register_settings), ПОСЛЕ хостовых register_setting — модули уже подписаны в boot()
		 * (plugins_loaded), поэтому успевают до options.php save.
		 *
		 * @param string $option_group Общая группа настроек страницы.
		 */
		do_action( 'plathix/settings/register', self::OPTION_GROUP );

		/**
		 * [internal]/102: реестр связи "опция → таб general" — Free объявляет свои 3
		 * host-опции по умолчанию, PRO-модуль сам дописывает свои через add_filter в СВОЁМ
		 * register() (симметрично plathix/settings/register/plathix/settings/general_sections/
		 * plathix/admin/settings_tabs). SettingsPage не хранит имён PRO-опций нигде —
		 * project_module_autonomy_invariant.
		 *
		 * @param array<int, string> $option_names
		 */
		$general_options = apply_filters( 'plathix/settings/option_tab_map', [
			'plathix_default_folder_id',
			'plathix_infinite_scroll',
			'plathix_bulk_safe_mode',
		] );
		$this->save_handler->register_tab_handler( 'general', is_array( $general_options ) ? $general_options : [], $this->loader );
	}

	// render_svg_toggle/roles/safe_mode удалены — SVG-секцию рендерит Modules\Svg\SvgSettings
	// ([internal]). Эти методы были UI-мёртвыми дублями (do_settings_sections не зовётся).

	public function render_infinite_scroll(): void {
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

	public function preserve_settings_tab(string $location, int $status): string {
		if ( ! str_contains( $location, 'page=' . self::PAGE_SLUG ) ) {
			return $location;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only redirect param, no data modified
		$tab = sanitize_key( (string) ( $_POST['_plathix_redirect_tab'] ?? '' ) );
		// Whitelist валидных табов — из дескрипторов (host + модульные через фильтр), без хардкода.
		if ( $tab !== '' && in_array( $tab, $this->view->tab_slugs(), true ) ) {
			$location = add_query_arg( 'tab', $tab, $location );
		}

		return $location;
	}

	public function settings_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * [internal]: делегирует в SettingsSaveHandler::register_tab_handler() на
	 * do_action('plathix/settings/register_tab', $tab_slug, $option_names) — модуль-владелец
	 * single-owner таба (SvgSettings/TrashSettings/PRO AccessSettings) сам знает список своих
	 * опций и вызывает этот хук из своего register(), не обращаясь к SettingsSaveHandler
	 * напрямую (decoupled, тот же паттерн, что plathix/settings/register).
	 *
	 * @param array<int, string> $option_names
	 */
	public function register_save_tab_handler(string $tab_slug, array $option_names): void {
		$this->save_handler->register_tab_handler( $tab_slug, $option_names, $this->loader );
	}

	/**
	 * Опции, которыми реально владеет эта страница ([internal]) — guard_option_updates()
	 * защищает только их, не весь префикс `plathix_`. Модульные опции (Trash/SVG/Access/
	 * ContentTypes/AttachmentMeta/Migrator/PresetSchema/Cache и т.д.) не проходят через
	 * этот guard: они пишутся вне admin-контекста штатно (boot-миграции, cron), и общий
	 * префиксный guard подменял их значение на $old_value / блокировал первичное создание
	 * опции (add_option через ветку pre_update_option при отсутствующей опции).
	 * `plathix_boot_recovered_lazily` — отдельное точечное исключение ниже ([internal]),
	 * не входит в этот список: она не опция SettingsPage, но обязана проходить через тот
	 * же guard c собственной типобезопасной проверкой значения.
	 */
	private const OWNED_OPTIONS = [
		'plathix_default_folder_id',
		'plathix_infinite_scroll',
		'plathix_bulk_safe_mode',
	];

	public function guard_option_updates(mixed $value, mixed $option, mixed $old_value): mixed {
		$is_boot_recovered_flag = 'plathix_boot_recovered_lazily' === $option;
		if ( ! is_string( $option ) || ( ! in_array( $option, self::OWNED_OPTIONS, true ) && ! $is_boot_recovered_flag ) ) {
			return $value;
		}

		if ( $this->can_manage_settings() ) {
			return $value;
		}

		// [internal]: plathix_boot_recovered_lazily — не пользовательская настройка, а
		// диагностический факт процесса (Taxonomy::ensure_ready() пишет его при чтении дерева
		// ЛЮБЫМ юзером, не только admin). Единственное безопасное исключение из admin-only
		// guard: точное имя опции (не паттерн/префикс) + строго типобезопасное значение —
		// расширять на другие опции нельзя без отдельного architecture-решения.
		if ( 'plathix_boot_recovered_lazily' === $option ) {
			$int_value = is_scalar( $value ) ? (int) $value : null;
			if ( null !== $int_value && in_array( $int_value, [ 0, 1 ], true ) ) {
				return $int_value;
			}
		}

		return $old_value;
	}

	public function can_manage_settings(): bool {
		return AccessResolver::currentUserIsFullAdmin();
	}
}
