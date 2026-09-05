<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderTreeBootstrapStrategy;
use Plathix\Core\RequestContext;
use Plathix\Core\RequestFolderResolver;
use Plathix\Core\TrashFolder;
use Plathix\Core\TaxonomyResolver;
use Plathix\Http\Nonce;
use Plathix\Http\RestController;
use Plathix\Infrastructure\Features;
use Plathix\User\AccessResolver;
use Plathix\User\Preferences;

final class SidebarRuntimeConfigBuilder
{
	public function __construct(
		private readonly FolderCountService $folders,
		private readonly SidebarBootstrapAssembler $assembler,
		private readonly SidebarI18nBuilder $i18n_builder,
		private readonly SidebarScreenResolver $screen_resolver,
	) {
	}

	/**
	 * @param array<string, mixed> $screen_ctx
	 * @return array<string, mixed>
	 */
	public function build(array $screen_ctx, string $post_type): array {
		$screen_context  = $screen_ctx['screen_context'];
		$screen_kind     = $screen_ctx['screen_kind'];
		$media_mode      = $screen_ctx['media_mode'];
		$filter_strategy = $screen_ctx['filter_strategy'];

		$taxonomy     = TaxonomyResolver::fromPostType( $post_type );
		$open_id      = RequestFolderResolver::resolve( $post_type, $taxonomy );
		$user_level   = AccessResolver::for_current_user();
		$pt_obj       = get_post_type_object( $post_type );
		$label_single = $pt_obj?->labels->singular_name ?? __( 'File', 'plathix' );
		$label_plural = $pt_obj?->labels->name ?? __( 'Files', 'plathix' );

		$auto_lazy_at            = FolderTreeBootstrapStrategy::threshold();
		$folder_count            = $this->folders->count_all( $taxonomy );
		$defer_folders_bootstrap = FolderTreeBootstrapStrategy::should_defer( $folder_count );

		$bootstrap_loaded_parents = [ 0 ];

		if ( $defer_folders_bootstrap ) {
			$bootstrap_folders = $this->assembler->build( $taxonomy, $open_id, $bootstrap_loaded_parents );
		} else {
			$folders           = $this->folders->get_all_cached( $taxonomy );
			$bootstrap_folders = $this->normalize_folder_payload( $folders );
		}

		$data = [
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'restUrl'    => esc_url_raw( trailingslashit( rest_url( 'plathix/' . RestController::API_VERSION ) ) ),
			// [internal]: запасной base в rest_route-стиле, единый источник —
			// RestController::rest_route_fallback_base() (было 4 дублирующих литерала).
			'restUrlFallback' => RestController::rest_route_fallback_base(),
			'wpMediaUrl' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			'nonce'      => Nonce::create(),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'postType' => $post_type,
			'taxonomy' => $taxonomy,
			'folders' => $bootstrap_folders,
			'openId' => $open_id,
			'openFolderId' => $open_id,
			'favorites' => Preferences::get_favorites( get_current_user_id(), $post_type ),
			'depthLimit' => (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH ),
			'userLevel' => $user_level->value,
			'caps' => RestController::get_cap_map_for_js( $post_type ),
			'imageSizes' => self::get_image_sizes_cached(),
			'lightboxZ' => (int) apply_filters( 'plathix/ui/z_index_lightbox', 160001 ),
			'svgSupport' => Features::is_enabled( 'svg' ),
			'postId' => (int) ( get_the_ID() ?: 0 ),
			// [internal] ([internal]): lazyTree JS-payload ключ удалён — 0 чтений на JS-стороне
			// (grep resources/js). PHP-логика Features::is_enabled('lazy_tree') остаётся: она
			// управляет $defer_folders_bootstrap/$bootstrap_loaded_parents выше, не только этим
			// JS-facing ключом.
			'autoLazyAt' => $auto_lazy_at,
			'deferFoldersBootstrap' => $defer_folders_bootstrap,
			'bootstrapLoadedParents' => array_values( array_map( 'intval', $bootstrap_loaded_parents ) ),
			'trashFolderId' => TrashFolder::id( $taxonomy ),
			'debug' => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'isTouch' => false,
			'postTypeLabel' => $label_single,
			'postTypeLabelPlural' => $label_plural,
			'i18n' => $this->i18n_builder->build( $pt_obj, $label_plural ),
			'features' => [
				'gallery' => Features::is_enabled( 'gallery' ),
				'import' => Features::is_enabled( 'import' ),
				// shortcodeBuilder + shortcodesMap УДАЛЕНЫ ([internal]): билдер = PRO-фича.
				// PRO строит map сам (GalleryShortcodeLauncher::shortcodes_map) и отдаёт своим
				// каналом (localize PlathixProBuilder на медиатеке), не через Free-конфиг.
			],
			'screenBase' => $screen_context,
			'screenKind' => $screen_kind,
			'mediaMode' => $media_mode,
			'filterStrategy' => $filter_strategy,
			// [internal] ([internal]): true, когда текущий запрос — редактор стороннего
			// page builder (Elementor/Beaver/Bricks/Divi/Oxygen), не собственный UI Plathix.
			// bootstrap-modal.js использует это, чтобы не применять persisted folder-фильтр
			// автоматически к чужому media picker'у при первом открытии ([internal]).
			'isForeignContext' => RequestContext::is_page_builder_request(),
			'infiniteScroll' => (bool) get_option( 'plathix_infinite_scroll', false ),
			// [internal] ([internal]): top-level, тот же паттерн, что infiniteScroll — раньше
			// эти два флага не имели PHP-источника вообще (ни здесь, ни в features[] ниже), JS
			// getFeatures() дефолтил их в true через `f.dnd !== false` на всегда-undefined ключе.
			'dnd' => Features::is_enabled( 'dnd' ),
			'uploadSync' => Features::is_enabled( 'upload_sync' ),
			'mediaModalOnly' => $screen_context === 'upload' && ! $this->screen_resolver->is_upload_list_page(),
			'isStaticLibraryScreen' => $screen_context === 'upload' && $this->screen_resolver->is_upload_list_page(),
			'bulkSafeMode' => (bool) get_option( 'plathix_bulk_safe_mode', true ),
		];

		$data = (array) apply_filters( 'plathix/sidebar/config', $data );

		return $this->apply_sidebar_overrides( $data );
	}

	/**
	 * Нормализовать полное дерево в bootstrap-payload, ЭКВИВАЛЕНТНЫЙ ответу REST
	 * `GET /plathix/v1/folders` (full-tree ветка FolderController::get_folders).
	 *
	 * [internal] ([internal]/L8): раньше bootstrap клал только `to_array()`
	 * без `hasChildren`/count-нормализации, поэтому клиент был вынужден дозапрашивать
	 * дерево по REST на init (index.js) — лишний полный WP-bootstrap round-trip на каждую
	 * загрузку медиатеки при ≤200 папках. Приводим форму bootstrap к форме REST здесь,
	 * чтобы этот init-дозапрос можно было снять (index.js): те же поля `hasChildren`
	 * (по наличию потомков) и `count: null → 0`.
	 *
	 * `has_children_map` строится тем же правилом, что в FolderController::get_folders
	 * (`parentId > 0`). Дублирование 6 строк осознанно: пока это 2 места (REST + сюда),
	 * извлекать общий хелпер по rule-of-three рано (см. [internal]). Появится 3-е
	 * место — тогда вынести.
	 *
	 * [internal]: hasChildren здесь ПЕРЕСЧИТЫВАЕТСЯ заново — sibling-эвристика по
	 * parentId среди уже загруженных в $folders элементов, перезаписывает то, что
	 * могло уже быть в DTO. Это ИНАЯ форма, чем в deferred-пути — см.
	 * SidebarBootstrapAssembler::build(), где hasChildren приходит напрямую из
	 * FolderDTO::to_array(), вычислено SQL-запросом. На практике расхождения нет: этот
	 * (non-deferred) путь всегда грузит полное дерево целиком, поэтому эвристика по
	 * текущей выборке не может разойтись с фактом. Формы сознательно не унифицированы —
	 * defer/eager остаются разными bootstrap-сценариями (WP Architecture Skeptic, issue
	 * #254).
	 *
	 * @param array<int, mixed> $folders
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_folder_payload(array $folders): array {
		$has_children_map = [];
		foreach ( $folders as $folder ) {
			$parent_id = (int) ( is_object( $folder ) ? ( $folder->parentId ?? 0 ) : ( $folder['parentId'] ?? 0 ) );
			if ( $parent_id > 0 ) {
				$has_children_map[ $parent_id ] = true;
			}
		}

		return array_map(
			static function (mixed $folder) use ($has_children_map): array {
				$data = is_object( $folder ) && method_exists( $folder, 'to_array' )
					? $folder->to_array()
					: (array) $folder;

				$data['hasChildren'] = ! empty( $has_children_map[ (int) ( $data['id'] ?? 0 ) ] );
				if ( array_key_exists( 'count', $data ) && $data['count'] === null ) {
					$data['count'] = 0;
				}

				return $data;
			},
			$folders
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function apply_sidebar_overrides(array $data): array {
		/**
		 * Add CSS classes to the sidebar root element.
		 *
		 * @param string[] $classes
		 */
		$skin_classes = (array) apply_filters( 'plathix/sidebar/root_classes', [] );
		// string-callable в array_map резолвится ТОЛЬКО через глобальный namespace (PHP-механика,
		// не WP-специфика) — namespace-стаб sanitize_html_class в тестах (если появится) его не
		// покроет. closure убирает зависимость от global-vs-namespace резолва ([internal]).
		$data['skinClasses'] = array_values( array_filter( array_map( static fn ($class) => sanitize_html_class( $class ), $skin_classes ) ) );

		/**
		 * Override the sidebar footer HTML.
		 *
		 * @param string $html
		 */
		$footer = (string) apply_filters( 'plathix/sidebar/footer_content', '' );
		if ( $footer !== '' ) {
			$data['footerContent'] = wp_kses_post( $footer );
		}

		/**
		 * Override the empty-state message shown when no folders exist.
		 *
		 * @param string $html
		 */
		$empty_state = (string) apply_filters( 'plathix/sidebar/empty_state', '' );
		if ( $empty_state !== '' ) {
			$data['emptyState'] = wp_kses_post( $empty_state );
		}

		/**
		 * Add extra buttons to the sidebar toolbar.
		 *
		 * Каждый дескриптор может нести необязательный `order` (int, меньше — левее).
		 * Дескрипторы без `order` едут в конец (PHP_INT_MAX). Порядок детерминирован:
		 * сортируем стабильно по (order, исходный индекс регистрации) — при равных весах
		 * сохраняется порядок подписки на фильтр. Это убирает зависимость видимого порядка
		 * иконок от порядка загрузки модулей ([internal]).
		 *
		 * `active` — необязательное имя boolean-поля в $store.plathix, отражающего текущее
		 * toggle-состояние кнопки ([internal]). Кнопки без постоянного состояния (открывающие
		 * модалку) его не передают — ключ в payload не появляется вовсе, рендер остаётся как
		 * раньше (backward compatible).
		 *
		 * @param array[] $actions
		 */
		$toolbar_extra = (array) apply_filters( 'plathix/sidebar/toolbar_extra', [] );
		if ( ! empty( $toolbar_extra ) ) {
			$data['toolbarExtra'] = array_values( array_map(
				function (array $item): array {
					$descriptor = [
						'id'    => sanitize_key( (string) ( $item['id'] ?? '' ) ),
						'title' => esc_attr( (string) ( $item['title'] ?? '' ) ),
						'icon'  => $this->sanitize_icon_html( (string) ( $item['icon'] ?? '' ) ),
					];

					// sanitize_key лоуэркейсит (реальный WP core behavior) — испортил бы camelCase
					// имя store-поля (`showFolderInfo` → `showfolderinfo`), с которым Free JS
					// физически не смог бы найти $store.plathix['showfolderinfo']. sanitize_html_class
					// сохраняет регистр и режет только на [a-zA-Z0-9_-] — безопасный идентификатор,
					// не текст, тот же выбор что уже сделан для skinClasses выше в этом методе.
					$active = sanitize_html_class( (string) ( $item['active'] ?? '' ) );
					if ( $active !== '' ) {
						$descriptor['active'] = $active;
					}

					return $descriptor;
				},
				self::sort_toolbar_extra( $toolbar_extra )
			) );
		}

		return $data;
	}

	/**
	 * Стабильно сортирует дескрипторы кнопок тулбара по `order` (меньше — левее).
	 *
	 * Дескрипторы без `order` едут в конец (PHP_INT_MAX). При равных `order` сохраняется
	 * порядок регистрации (декорирование исходным индексом — PHP usort не стабилен). Так
	 * видимый порядок иконок перестаёт зависеть от порядка загрузки модулей ([internal]).
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function sort_toolbar_extra(array $items): array {
		$decorated = [];
		$index     = 0;
		foreach ( $items as $item ) {
			$item        = (array) $item;
			$order       = isset( $item['order'] ) ? (int) $item['order'] : PHP_INT_MAX;
			$decorated[] = [ 'order' => $order, 'index' => $index++, 'item' => $item ];
		}

		usort(
			$decorated,
			static function (array $a, array $b): int {
				return [ $a['order'], $a['index'] ] <=> [ $b['order'], $b['index'] ];
			}
		);

		return array_map( static fn (array $entry): array => $entry['item'], $decorated );
	}

	private function sanitize_icon_html(string $html): string {
		return \Plathix\Helpers\Sanitize::icon_markup( $html );
	}

	/** @return string[] */
	private static function get_image_sizes_cached(): array {
		static $sizes = null;
		if ( $sizes === null ) {
			$sizes = array_values( array_unique( array_merge( [ 'thumbnail', 'medium', 'large', 'full' ], array_values( get_intermediate_image_sizes() ) ) ) );
		}
		return $sizes;
	}
}
