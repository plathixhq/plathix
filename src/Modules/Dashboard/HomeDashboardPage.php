<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard;

use Plathix\Core\AdminLayout;
use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Keys;
use Plathix\Modules\Dashboard\Widgets\UploadsWidget;
use Plathix\Modules\Dashboard\Widgets\MimeTypesWidget;
use Plathix\Modules\Dashboard\Widgets\OrphanedFilesWidget;
use Plathix\Modules\Dashboard\Widgets\FoldersWidget;
use Plathix\Modules\Dashboard\Widgets\MigrationBannerWidget;
use Plathix\Modules\Dashboard\Widgets\PresetWidget;
use Plathix\Modules\Dashboard\Widgets\StatusBarWidget;
use Plathix\PublicApi\SystemInfoApi;
use Plathix\User\AccessLevel;

/**
 * Страница Home Dashboard.
 */
class HomeDashboardPage
{
	public const PAGE_SLUG = 'plathix';
	public const DISMISS_META_KEY = 'plathix_onboarding_dismissed';
	public const MIGRATION_DISMISS_META_KEY = 'plathix_migration_dismissed';

	/**
	 * [internal]/#649: формула вынесена в Keys::blog_suffix() (единственный Free-владелец,
	 * избавляет от дословной копии тела рядом с Preferences::blog_suffix()). Public, т.к.
	 * HomeDashboardData читает те же ключи для того же юзера — обе стороны обязаны
	 * получать один и тот же ключ, иначе write/read разъедутся.
	 */
	public static function blog_scoped_meta_key(string $base): string {
		return $base . Keys::blog_suffix();
	}

	/**
	 * Ключи миграционных источников, dismiss которых мы принимаем (whitelist для sanitize).
	 * Синхронно с HomeDashboardData::detect_migration_plugin labels.
	 *
	 * @var list<string>
	 */
	private const MIGRATION_SOURCES = [ 'filebird', 'wpmediafolder', 'realmedialib', 'happyfiles', 'wickedfolders' ];

	/** Регистрирует страницу в меню WP и AJAX-обработчик. */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 9 );
		add_action( 'wp_ajax_plathix_dismiss_onboarding', [ $this, 'handle_dismiss' ] );
		add_action( 'wp_ajax_plathix_dismiss_migration', [ $this, 'handle_dismiss_migration' ] );
		// Home — корневая страница меню Plathix. Владелец корня отдаёт его slug через
		// extension point plathix/admin/root_slug ([internal]): страницы-потребители
		// (напр. ShortcodesListPage) берут parent для add_submenu_page отсюда, а не через
		// прямой HomeDashboardPage::PAGE_SLUG — так фичевые модули не зависят от модуля
		// Dashboard. Без Dashboard фильтр отдаёт свой default 'plathix'.
		add_filter( 'plathix/admin/root_slug', static fn (): string => self::PAGE_SLUG );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'Home', 'plathix' ),
				'is_plathix_page' => true,
				'is_root'         => true,
				'section'         => 'main',
				'order'           => 10,
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
			];
			return $pages;
		} );
	}

	/** Добавляет корневую страницу меню и её первый submenu-пункт. */
	public function add_page(): void {
		add_menu_page(
			__( 'Plathix', 'plathix' ),
			__( 'Plathix', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ],
			'dashicons-category',
			79
		);

		// WP требует явный первый submenu чтобы он не дублировал заголовок родителя
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Plathix', 'plathix' ),
			__( 'Home', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * AJAX: сохраняет отметку о закрытии ОДНОЙ карточки онбординга текущим пользователем
	 * ([internal] — раньше один × гасил весь блок целиком, включая будущие/PRO-карточки).
	 *
	 * Форма по образцу handle_dismiss_migration() ниже (per-id список + advisory lock), но
	 * не общий метод: card_id не проверяется против allowlist, т.к. карточки — динамический
	 * список из collect_onboarding_cards() (включая PRO-фильтр), а не фиксированный enum как
	 * MIGRATION_SOURCES — полная валидация потребовала бы повторного вызова сборки карточек
	 * внутри AJAX-хендлера ради проверки, которая не добавляет защиты (неизвестный id просто
	 * не совпадёт ни с одной карточкой при следующем рендере).
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( 'plathix_dismiss_onboarding', 'nonce' );

		// [internal] (follow-up находка): голый current_user_can() не видит per-role/per-user
		// access-level override (plathix/user/access_level, PRO RolePolicy) — AjaxGuard::require_cap()
		// проверяет через AccessResolver сначала.
		AjaxGuard::require_cap( AccessLevel::Full, 'manage_options' );

		$card_id = isset( $_POST['card_id'] ) ? sanitize_key( wp_unslash( $_POST['card_id'] ) ) : '';
		if ( '' === $card_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing card id.', 'plathix' ) ], 400 );
		}

		$user_id  = get_current_user_id();
		$meta_key = self::blog_scoped_meta_key( self::DISMISS_META_KEY );

		// [internal] ([internal]) паттерн: read+append+write без лока теряет запись при
		// конкурентном dismiss двух РАЗНЫХ карточек (две вкладки). Отдельный лок от
		// dismiss_migration_ — разные операции над разными meta, не обязаны сериализоваться
		// друг с другом.
		$lock_name = Keys::lock( 'dismiss_onboarding_' . $user_id );
		$acquired  = DbAdvisoryLock::acquire( $lock_name, 3 );

		try {
			$dismissed = get_user_meta( $user_id, $meta_key, true );
			$dismissed = is_array( $dismissed ) ? $dismissed : [];

			if ( ! in_array( $card_id, $dismissed, true ) ) {
				$dismissed[] = $card_id;
				update_user_meta( $user_id, $meta_key, array_values( array_unique( $dismissed ) ) );
			}
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release( $lock_name );
			}
		}

		wp_send_json_success();
	}

	/**
	 * Сохраняет dismiss миграционного баннера per-source per-user ([internal]).
	 *
	 * Вариант 2 «скрыть до нового обнаружения»: ключ обнаруженного источника добавляется в
	 * user_meta-список; detect_migration_plugin пропускает dismissed-источники, но покажет баннер
	 * для НОВОГО (не-dismissed) источника. Персонально по юзеру (как онбординг-dismiss).
	 */
	public function handle_dismiss_migration(): void {
		check_ajax_referer( 'plathix_dismiss_migration', 'nonce' );

		// [internal] (follow-up находка): см. комментарий в handle_dismiss() выше.
		AjaxGuard::require_cap( AccessLevel::Full, 'manage_options' );

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! in_array( $source, self::MIGRATION_SOURCES, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown source.', 'plathix' ) ], 400 );
		}

		$user_id  = get_current_user_id();
		$meta_key = self::blog_scoped_meta_key( self::MIGRATION_DISMISS_META_KEY );

		// [internal] ([internal]): read+append+write без лока — конкурентный dismiss
		// двух РАЗНЫХ источников (две вкладки) мог терять одну из записей (T2 читает
		// старый список ДО того, как T1 записал свой). Per-user лок (не per-source: race
		// возможен МЕЖДУ разными source) сериализует все dismiss-вызовы одного юзера,
		// timeout=3с — короткая критическая секция, симметрично Preferences::merge_favorites()
		// ([internal], [internal]). GET_LOCK недоступность деградирует к best-effort без
		// лока, как и раньше этого пакета — ответ AJAX не меняется в любом случае.
		$lock_name = Keys::lock( 'dismiss_migration_' . $user_id );
		$acquired  = DbAdvisoryLock::acquire( $lock_name, 3 );

		try {
			$dismissed = get_user_meta( $user_id, $meta_key, true );
			$dismissed = is_array( $dismissed ) ? $dismissed : [];

			if ( ! in_array( $source, $dismissed, true ) ) {
				$dismissed[] = $source;
				update_user_meta( $user_id, $meta_key, array_values( array_unique( $dismissed ) ) );
			}
		} finally {
			if ( $acquired ) {
				DbAdvisoryLock::release( $lock_name );
			}
		}

		wp_send_json_success();
	}

	/**
	 * Реестр виджетов дашборда ([internal]).
	 *
	 * Хост объявляет свои 11 виджетов как host-дескрипторы; модули могут добавлять/убирать
	 * свои через фильтр `plathix/dashboard/widgets` (как `plathix/admin/settings_tabs`).
	 * Читается лениво на render-time. Каждый дескриптор:
	 *   slug   string   — ключ виджета
	 *   zone   string   — зона: pre-grid | row-analytics-top | row-top | content | row-bottom
	 *   order  int      — порядок внутри зоны (по возрастанию)
	 *   render callable  — рендер виджета, получает $data
	 *
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
			// [internal]: виджет «Gallery Shortcodes» (shortcodes, zone row-bottom order 10)
			// уехал в PRO вместе с галереей. Free его НЕ рендерит; PRO добавляет свой дескриптор
			// через фильтр plathix/dashboard/widgets. Без PRO виджета галерейных шорткодов нет.
			// [internal] ([internal]): виджет «недавняя активность» (activity-compact)
			// уехал в PRO вместе с журналом. Free его НЕ рендерит. PRO добавляет свой дескриптор
			// (zone row-bottom, order 20) через фильтр plathix/dashboard/widgets ниже.
			// [internal]: виджеты «Content Types» (content, order 10) и «Quick Links»
			// (row-bottom, order 30) уехали в PRO целиком. Free их НЕ рендерит. PRO добавляет
			// свои дескрипторы через фильтр plathix/dashboard/widgets (те же slug/zone/order).
		];

		/**
		 * Фильтр-реестр виджетов дашборда.
		 * Модуль добавляет/убирает дескриптор виджета (slug/zone/order/render).
		 *
		 * @param array<int, array{slug:string,zone:string,order:int,render:callable}> $widgets
		 */
		$widgets = apply_filters( 'plathix/dashboard/widgets', $host );

		return is_array( $widgets ) ? array_values( $widgets ) : $host;
	}

	/**
	 * Карта зон → список slug'ов виджетов в порядке рендера (для проверяемости реестра).
	 * Public: используется тестами; рендер виджетов не запускается.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function widget_zones(): array {
		$out = [];
		foreach ( $this->widgets_by_zone() as $zone => $list ) {
			$out[ $zone ] = array_map( static fn (array $w): string => (string) $w['slug'], $list );
		}
		return $out;
	}

	/** Точка входа: собирает данные и рендерит страницу. */
	public function render(): void {
		AdminLayout::render_page( self::PAGE_SLUG, function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}

			// [internal]: раньше здесь стоял безусловный Cache::delete_group(DASHBOARD_STATS_GROUP)
			// на каждый визит — это version-bump примитив (см. Cache::bump_version), убивавший
			// часовой TTL всех метрик дашборда ДО первого чтения, независимо от того, реально
			// ли что-то изменилось. Событийная инвалидация (Cache::on_attachment_change на
			// add/delete/edit_attachment, Cache::on_folder_audit_event на folder-мутациях,
			// UserFavoritesService::invalidate на plathix/favorites/changed) уже покрывает
			// mime/upload/favorites-статистику точечно — тотальный сброс здесь дублировал её
			// грубее и уничтожал сам смысл кеширования. ([internal]: shortcode_stats уехал в
			// PRO целиком — его собственная событийная инвалидация теперь там же, см.
			// PlathixPro\Modules\Gallery\ShortcodeUsageScanner::invalidate_dashboard_stats().)
			$data = ( new HomeDashboardData() )->collect();
			// [internal]: Dashboard JS вынесен в отдельный entry.
			// admin-ui.js (Rail/InlineTabs) грузит AdminUiEnqueueService — дублировать не нужно.
			$dash_asset_file = PLATHIX_ASSETS_PATH . 'js/admin-ui/dashboard.asset.php';
			$dash_asset      = file_exists( $dash_asset_file ) ? require $dash_asset_file : [];
			$dash_version    = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $dash_asset['version'] ?? PLATHIX_VERSION );

			if ( file_exists( PLATHIX_ASSETS_PATH . 'js/admin-ui/dashboard.js' ) ) {
				wp_enqueue_script(
					'plathix-dashboard-ui',
					PLATHIX_ASSETS_URL . 'js/admin-ui/dashboard.js',
					(array) ( $dash_asset['dependencies'] ?? [] ),
					$dash_version,
					true
				);
				wp_localize_script( 'plathix-dashboard-ui', 'PlathixDashboard', [
					'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
					'dismissNonce'          => wp_create_nonce( 'plathix_dismiss_onboarding' ),
					'migrationDismissNonce' => wp_create_nonce( 'plathix_dismiss_migration' ),
					'systemInfoUrl'         => ( new SystemInfoApi() )->pageUrl(),
				] );
				wp_set_script_translations( 'plathix-dashboard-ui', 'plathix', PLATHIX_PATH . 'languages' );
			}

			// CSS Dashboard-виджетов co-located в модуле ([internal], #113):
			// Free-виджет-специфика вынесена из общего admin-ui.css в dashboard.css и грузится
			// ТОЛЬКО здесь, на Dashboard, а не на всех admin-страницах. dep ['plathix-admin-ui']
			// гарантирует порядок: shared bento / .plathix-btn / переменные из admin-ui.css приезжают
			// первыми. Зеркало WizardAssets (free-wizard.css).
			if ( file_exists( PLATHIX_ASSETS_PATH . 'css/admin-ui/dashboard.css' ) ) {
				wp_enqueue_style(
					'plathix-dashboard',
					PLATHIX_ASSETS_URL . 'css/admin-ui/dashboard.css',
					[ 'plathix-admin-ui' ],
					$dash_version
				);
			}

		// Реестр виджетов, сгруппированный по зонам и отсортированный по order ([internal]).
			$zones = $this->widgets_by_zone();
			?>
		<div class="plathix-page">
			<?php
			// A11y-якорь ([internal]/C1): дашборд намеренно без крупного заголовка, но
			// скринридеру нужен <h1> страницы, под который встают <h2> виджетов. Даём его
			// визуально-скрытым нативным WP-классом screen-reader-text (вид не меняется).
			?>
			<h1 class="screen-reader-text"><?php esc_html_e( 'Plathix Dashboard', 'plathix' ); ?></h1>
			<?php
			// pre-grid: full-width виджеты сверху (StatusBar, MigrationBanner) — без обёртки ряда.
			$this->render_zone( $zones, 'pre-grid', $data );
			/**
			 * Точка расширения: модуль Onboarding рендерит сюда setup-блок «Finish setup».
			 * Dashboard собирает данные (onboarding_cards/show_onboarding) и передаёт их в
			 * хук, но не знает, кто подписан — если Onboarding отключён, это no-op.
			 */
			do_action( 'plathix/dashboard/render_onboarding', $data );
			$this->render_main_grid( $zones, $data );
			/**
			 * Точка расширения: модуль Onboarding рендерит сюда first-run модалку визарда.
			 * Dashboard не знает, кто подписан — если Onboarding отключён, это no-op.
			 */
			do_action( 'plathix/onboarding/render_modal' );
			?>
		</div>
			<?php
		} );
	}

	/**
	 * Группирует дескрипторы реестра по зонам, внутри зоны сортирует по order.
	 *
	 * @return array<string, array<int, array{slug:string,zone:string,order:int,render:callable}>>
	 */
	private function widgets_by_zone(): array {
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
	 * Рендерит виджеты одной зоны (без обёртки-ряда). Пустая зона — ничего не печатает.
	 *
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */
	private function render_zone(array $zones, string $zone, array $data): void {
		foreach ( $zones[ $zone ] ?? [] as $widget ) {
			( $widget['render'] )( $data );
		}
	}

	/**
	 * Рендерит виджеты зоны внутри обёртки `plathix-home__row plathix-home__row--<suffix>`.
	 * Если в зоне нет виджетов — обёртка не печатается (нет пустого ряда).
	 *
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */
	private function render_row(array $zones, string $zone, string $suffix, array $data): void {
		if ( empty( $zones[ $zone ] ) ) {
			return;
		}
		echo '<div class="plathix-home__row plathix-home__row--' . esc_attr( $suffix ) . '">';
		$this->render_zone( $zones, $zone, $data );
		echo '</div>';
	}

	/**
	 * @param array<string, array<int, array{render:callable}>> $zones
	 * @param array<string, mixed> $data
	 */
	private function render_main_grid(array $zones, array $data): void {
		$this->render_row( $zones, 'row-analytics-top', 'analytics-top', $data );
		$this->render_row( $zones, 'row-top', 'top', $data );
		// content: full-width виджеты в гриде (ContentTypes) — без обёртки ряда.
		$this->render_zone( $zones, 'content', $data );
		$this->render_row( $zones, 'row-bottom', 'bottom', $data );

		// Safe fallback: дескрипторы с незнакомой зоной рендерятся в конце грида, не роняя страницу.
		$known = [ 'pre-grid', 'row-analytics-top', 'row-top', 'content', 'row-bottom' ];
		foreach ( $zones as $zone => $widgets ) {
			if ( ! in_array( $zone, $known, true ) ) {
				$this->render_zone( $zones, $zone, $data );
			}
		}
		// [internal]: раздел Документация = PRO. Nudge со ссылкой на Docs показываем
		// только если PRO активен (фильтр вернул непустой URL). Без PRO раздела нет → nudge нет.
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
