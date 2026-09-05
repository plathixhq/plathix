<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Core\FolderQuery;
use Plathix\Core\FolderRepository;
use Plathix\Core\TrashFolder;
use Plathix\Core\TaxonomyResolver;
use Plathix\Core\MultilingualCompat;
use Plathix\Helpers\Sanitize;

class ListScreenFragmentsController
{
	private const AJAX_ACTION = 'plathix_list_screen';

	private ?FolderRepository $folder_repository = null;

	private readonly ListScreenAuthorizer $authorizer;

	/**
	 * FolderRepository внедряется через конструктор (P4) — граф собирается в composition root
	 * `Modules\ListScreen\Module`. Дефолт null + lazy-getter `folder_repository()` сохраняют
	 * совместимость с тестами, создающими контроллер без аргументов.
	 *
	 * Авторизация вынесена в соучастника ListScreenAuthorizer ([internal]): отдельная причина
	 * меняться (модель доступа), не зависит от рендера. Дефолт new — соучастник без своего состояния.
	 */
	public function __construct(?FolderRepository $folder_repository = null, ?ListScreenAuthorizer $authorizer = null) {
		$this->folder_repository = $folder_repository;
		$this->authorizer = $authorizer ?? new ListScreenAuthorizer();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle' ] );
	}

	/**
	 * @api EXTENSION POINT (CEC-201, [internal]). Класс объявлен точкой
	 * расширения в {@see \Plathix\DevContracts\PublicContractRegistry}; PRO-контроллер списков
	 * платных типов наследует перечисленные ниже protected-методы. Правила:
	 *  - НЕ возвращать `final` классу и не сужать эти методы до private без снятия записи
	 *    из реестра (гейт PRO покраснеет — это ожидаемое поведение, а не помеха);
	 *  - внутри них ЗАПРЕЩЁН хардкод `'attachment'`: тип приходит из запроса (см. CEC-202,
	 *    иначе родитель лжёт наследнику);
	 *  - изменение поведения любого из них требует обновления behavior_test записи реестра.
	 *
	 * Объявленные точки: parse_request, build_get_args, replace_request_globals,
	 * restore_request_globals, add_fragment_folder_filter, capture_fragments,
	 * build_common_query_params, json_success, json_error.
	 */
	public function handle(): void {
		$request = $this->parse_request();
		$this->authorizer->authorize( $request );

		try {
			// CTAN-201: attachment-native — контроллер рендерит только медиатеку (upload).
			// Не-upload screen_base отсечён авторизацией (ALLOWED_SCREEN_BASES) до этой точки.
			$fragments = $this->render_upload_fragments( $request );
		} catch ( \Throwable $e ) {
			$this->json_error( 'Render failed.', 500 );
			return;
		}

		$this->json_success( [
			'screenBase' => $request['screen_base'],
			'postType'   => $request['post_type'],
			'folderId'   => $request['folder_id'],
			'url'        => $this->build_canonical_url( $request ),
			'fragments'  => $fragments,
		] );
	}

	/**
	 * Parses read-only list-screen navigation params from $_REQUEST.
	 *
	 * Nonce/cap are verified in authorize() (Nonce::verify_or_die + current_user_can),
	 * which handle() calls BEFORE any parsed value is used to render or query. No value
	 * here is written to the DB or output unescaped; the sniff's NonceVerification.Recommended
	 * is satisfied by the authorize() gate, hence the per-line ignores below.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_request(): array {
		$order_raw = strtoupper( sanitize_key( (string) wp_unslash( $_REQUEST['order'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param; nonce+cap verified in authorize() before use

		// Keys consumed by this handler; everything else passes through to the list table
		// so third-party plugin filters (WooCommerce, CPT taxonomies, etc.) are preserved.
		static $known_keys = [
			'action', 'nonce', '_wpnonce', '_wp_http_referer',
			'screen_base', 'post_type', 'folder_id', 'paged',
			'orderby', 'order', 's', 'm', 'author',
			'post_status', 'post_mime_type',
		];

		// [internal]: служебные ключи навигации/роутинга НЕ пробрасываются как «сторонний фильтр» —
		// иначе шаренная/битая ссылка тихо переопределяет folder/status/mode (link-manipulation +
		// ломает fidelity). Отдельно от $known_keys: те — параметры хендлера, эти — чужая
		// admin-навигация, которую нельзя допускать ни в грид, ни в canonical URL.
		static $blocked_extra_keys = [ 'page', 'mode', 'status', 'plathix_folder' ];

		$extra_params = [];
		foreach ( $_REQUEST as $key => $raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav params; nonce+cap verified in authorize() before use
			$safe_key = sanitize_key( (string) $key );
			if (
				$safe_key !== ''
				&& ! in_array( $safe_key, $known_keys, true )
				&& ! in_array( $safe_key, $blocked_extra_keys, true )
			) {
				// [internal]: структуросохраняющий sanitize — array-фильтры (product_cat[]) не вырождаются
				// в 'Array'. Прежний (string)-каст ломал не-scalar params.
				$value = Sanitize::deep_text( wp_unslash( $raw ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified in authorize(); Sanitize::deep_text() applies sanitize_text_field() recursively to every leaf
				// [internal]: пустое значение ≠ фильтр. Голые query-ключи без значения
				// (WooCommerce «woocommerce-login-nonce» → $_GET['...']='') прежде проходили как
				// «сторонний фильтр» и текли в extra_params → canonical URL (мусор в адресной строке).
				// Легитимный фильтр всегда несёт значение; WP_Query/WP_List_Table на key='' не
				// фильтруют, поэтому отбрасывание пустых значений НЕ теряет ни одного реального
				// фильтра (уточнение критерия [internal], не отмена). Непустой массив (product_cat[]) цел.
				if ( is_array( $value ) ? $value !== [] : $value !== '' ) {
					$extra_params[ $safe_key ] = $value;
				}
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav params; nonce+cap verified in authorize() before any parsed value is used
		return [
			'screen_base'    => sanitize_key( (string) wp_unslash( $_REQUEST['screen_base'] ?? 'upload' ) ),
			'post_type'      => sanitize_key( (string) wp_unslash( $_REQUEST['post_type'] ?? 'attachment' ) ),
			'folder_id'      => absint( wp_unslash( $_REQUEST['folder_id'] ?? 0 ) ),
			'paged'          => max( 1, absint( wp_unslash( $_REQUEST['paged'] ?? 1 ) ) ),
			// [internal] ([internal]): sanitize_key() на всей строке целиком портил
			// compound-orderby (несколько ключей через пробел, WP core:
			// WP_Query::parse_orderby() -> explode(' ', ...)), напр. "menu_order title" ->
			// "menu_ordertitle". ListScreenQueryContext::sanitizeOrderby() санитизирует
			// каждый пробел-разделённый токен отдельно, сохраняя составные значения.
			'orderby'        => ListScreenQueryContext::sanitizeOrderby( (string) wp_unslash( $_REQUEST['orderby'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside sanitizeOrderby() via sanitize_key() per token
			'order'          => in_array( $order_raw, [ 'ASC', 'DESC' ], true ) ? $order_raw : '',
			's'              => sanitize_text_field( (string) wp_unslash( $_REQUEST['s'] ?? '' ) ),
			'm'              => absint( wp_unslash( $_REQUEST['m'] ?? 0 ) ),
			'author'         => absint( wp_unslash( $_REQUEST['author'] ?? 0 ) ),
			'post_status'    => sanitize_key( (string) wp_unslash( $_REQUEST['post_status'] ?? '' ) ),
			'post_mime_type' => sanitize_text_field( (string) wp_unslash( $_REQUEST['post_mime_type'] ?? '' ) ),
			'extra_params'   => $extra_params,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{views: string, topNav: string, list: string, bottomNav: string}
	 */
	private function render_upload_fragments(array $args): array {
		$this->ensure_upload_list_table_loaded();
		set_current_screen( 'upload' );

		$taxonomy  = TaxonomyResolver::fromPostType( 'attachment' );
		$folder_id = (int) $args['folder_id'];
		$is_trash  = $folder_id > 0 && $folder_id === TrashFolder::id( $taxonomy );
		// upload-режим: подделываем контекст экрана медиатеки для сторонних parse_query-фильтров.
		$saved_state = $this->replace_request_globals( $this->build_get_args( $args, 'list' ), $is_trash, 'upload.php', 'attachment' );

		$filter = $this->add_fragment_folder_filter( $args['folder_id'], $taxonomy, (string) ( $args['post_type'] ?? 'attachment' ) );

		try {
			$table  = new \WP_Media_List_Table( [ 'screen' => get_current_screen() ] );
			$table->prepare_items();
			return $this->capture_fragments( $table );
		} finally {
			remove_filter( 'pre_get_posts', $filter, 5 );
			$this->restore_request_globals( $saved_state );
		}
	}

	/**
	 * @return array{views: string, topNav: string, list: string, bottomNav: string}
	 */
	protected function capture_fragments(\WP_List_Table $table): array {
		ob_start();
		$table->views();
		$views      = (string) ob_get_clean();
		ob_start();
		$table->display_tablenav( 'top' );
		$top_nav    = (string) ob_get_clean();
		ob_start();
		$table->display_rows_or_placeholder();
		$rows       = (string) ob_get_clean();
		ob_start();
		$table->display_tablenav( 'bottom' );
		$bottom_nav = (string) ob_get_clean();

		return [
			'views'     => $views,
			'topNav'    => $top_nav,
			'list'      => $rows,
			'bottomNav' => $bottom_nav,
		];
	}

	/**
	 * Adds a temporary pre_get_posts filter for fragment rendering.
	 * Bypasses is_main_query() — list tables run sub-queries in AJAX context,
	 * so the standard FolderQuery guard would silently skip them.
	 */
	protected function add_fragment_folder_filter(int $folder_id, string $taxonomy, string $post_type): callable {
		$filter = function (\WP_Query $q) use ($folder_id, $taxonomy, $post_type): void {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen instanceof \WP_Screen ) {
				return;
			}

			if ( $screen->id !== 'upload' && $screen->base !== 'edit' ) {
				return;
			}

			// [internal] ([internal]): та же логика, что [internal] — orderby=parent нестабилен
			// независимо от выбранной папки, применяем ДО folder_id<=0 return, иначе AJAX-фрагмент
			// для "все файлы" останется без tiebreak.
			FolderQuery::apply_parent_orderby_tiebreak( $q );

			if ( $folder_id <= 0 ) {
				return;
			}

			$existing = (array) ( $q->get( 'tax_query' ) ?: [] );
			foreach ( $existing as $clause ) {
				if ( is_array( $clause ) && ( $clause['taxonomy'] ?? '' ) === $taxonomy && isset( $clause['terms'] ) ) {
					return;
				}
			}

			if ( $folder_id === TrashFolder::id( $taxonomy ) ) {
				// CEC-202 ([internal]): тип берётся из запроса, а не
				// хардкодится. Метод объявлен extension point (@api ниже) и наследуется
				// PRO-контроллером списков платных типов: хардкод 'attachment' означал бы,
				// что родитель лжёт наследнику — корзина папки на списке записей молча
				// отрендерила бы вложения. Сегодня ветка недостижима для PRO-таксономий
				// (TrashFolder::id вернёт 0, пока Trash их не видит), поэтому фикс
				// поведение Free не меняет — но снимает мину до её срабатывания.
				$q->set( 'tax_query', [] );
				$q->set( 'post_status', 'trash' );
				$q->set( 'post_type', $post_type );
				return;
			}

			$repo = $this->folder_repository();
			if ( $repo->is_uncategorized_folder( $folder_id, $taxonomy ) ) {
				$clause = [ 'taxonomy' => $taxonomy, 'operator' => 'NOT EXISTS' ];
			} else {
				$clause = [
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => [ $folder_id ],
					'include_children' => false,
				];
			}

			$q->set( 'tax_query', array_merge( $existing, [ $clause ] ) );
			MultilingualCompat::suppress_for_query( $q );
		};

		// pre_get_posts callbacks mutate the WP_Query by reference and return nothing —
		// it is conventionally an action hook, so register via add_action.
		add_action( 'pre_get_posts', $filter, 5 );

		return $filter;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	protected function build_get_args(array $args, string $mode = ''): array {
		// Start from pass-through params so third-party plugin filters survive the transition.
		$get = $args['extra_params'] ?? [];
		$get['post_type'] = $args['post_type'];

		if ( $mode !== '' ) {
			$get['mode'] = $mode;
		}
		$taxonomy = TaxonomyResolver::fromPostType( $args['post_type'] );
		$folder_id = (int) $args['folder_id'];
		$is_trash  = $folder_id > 0 && $folder_id === TrashFolder::id( $taxonomy );
		if ( $folder_id > 0 && ! $is_trash ) {
			$get['plathix_folder'] = (string) $args['folder_id'];
		}
		if ( $is_trash ) {
			// [internal]: WP-медиатека в list-режиме включает корзину по параметру
			// `attachment-filter=trash`, НЕ по `status`. Ядровая wp_edit_attachments_query_vars()
			// второй строкой безусловно перетирает post_status: если нет attachment-filter=trash,
			// статус сбрасывается на inherit,private (доказано на проде: status=trash → 320 не-trash;
			// attachment-filter=trash → 20 trashed). WP_Media_List_Table::$is_trash тоже читается из
			// $_REQUEST['attachment-filter'] (class-wp-media-list-table.php:124). Даём ядру его
			// нативный контракт вместо борьбы через свой pre_get_posts.
			$get['attachment-filter'] = 'trash';
		}
		if ( $args['paged'] > 1 ) {
			$get['paged'] = (string) $args['paged'];
		}
		if ( $args['s'] !== '' ) {
			$get['s'] = $args['s'];
		}
		if ( $args['m'] > 0 ) {
			$get['m'] = (string) $args['m'];
		}
		if ( $args['author'] > 0 ) {
			$get['author'] = (string) $args['author'];
		}
		if ( $args['orderby'] !== '' ) {
			$get['orderby'] = $args['orderby'];
		}
		if ( $args['order'] !== '' ) {
			$get['order'] = $args['order'];
		}
		if ( ( $args['post_status'] ?? '' ) !== '' ) {
			$get['post_status'] = $args['post_status'];
		}
		if ( ( $args['post_mime_type'] ?? '' ) !== '' ) {
			$get['post_mime_type'] = $args['post_mime_type'];
		}

		return $get;
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private function build_canonical_url(array $request): string {
		$params = [];
		$taxonomy = TaxonomyResolver::fromPostType( $request['post_type'] );
		$request_folder_id = (int) $request['folder_id'];
		$is_trash = $request_folder_id > 0 && $request_folder_id === TrashFolder::id( $taxonomy );

		// CTAN-201: screen_base здесь всегда 'upload' (ALLOWED_SCREEN_BASES); edit-ветка
		// canonical URL уехала в PRO-fragments-контроллер вместе с CPT-рендером.
		$base = admin_url( 'upload.php' );
		$params['mode'] = 'list';

		$params = array_merge( $params, $this->build_common_query_params( $request ) );

		// [internal]: сторонние pass-through фильтры (extra_params) — в canonical URL, чтобы URL
		// round-trip совпадал с тем, что грид уже применил (add_query_arg штатно кодирует
		// array-значения key[]=v). Служебные ключи уже отсеяны блеклистом в parse_request, конфликта
		// с mode/status/folder нет.
		if ( ! empty( $request['extra_params'] ) && is_array( $request['extra_params'] ) ) {
			$params = array_merge( $params, $request['extra_params'] );
		}

		if ( $is_trash ) {
			// [internal]: canonical URL корзины несёт только attachment-filter=trash —
			// единственный ключ, по которому НАТИВНАЯ загрузка upload.php (прямой заход/reload/
			// копия ссылки) реально включает post_status=trash (ядро wp_edit_attachments_query_vars
			// перетирает status). Без него открытый напрямую URL корзины пуст (факт с прода:
			// ?status=trash → 0, ?attachment-filter=trash → 20). JS-детект корзины читает
			// attachment-filter первым ([internal]), status=trash избыточен.
			$params['attachment-filter'] = 'trash';
		} elseif ( $request_folder_id > 0 ) {
			// [internal]: без этого ключа canonical URL терял текущую папку — клик по папке
			// в сайдбаре переключал список (AJAX), но history.pushState получал URL без
			// plathix_folder, и F5/reload открывал дефолтную папку вместо текущей. Только
			// для обычных папок — корзина уже покрыта attachment-filter=trash выше.
			$params['plathix_folder'] = (string) $request_folder_id;
		}

		// [internal]: esc_url_raw — add_query_arg НЕ экранирует; URL уходит в JSON → history.pushState.
		// Страховка от будущего JS-синка, который сунул бы data.url в href/innerHTML (reflected-XSS).
		// Data-context (не HTML-атрибут) → esc_url_raw, не esc_url.
		return esc_url_raw( add_query_arg( $params, $base ) );
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, string>
	 */
	protected function build_common_query_params(array $request): array {
		$params = [];

		if ( $request['paged'] > 1 ) {
			$params['paged'] = (string) $request['paged'];
		}
		if ( $request['s'] !== '' ) {
			$params['s'] = $request['s'];
		}
		if ( $request['orderby'] !== '' ) {
			$params['orderby'] = $request['orderby'];
		}
		if ( $request['order'] !== '' ) {
			$params['order'] = $request['order'];
		}
		if ( $request['m'] > 0 ) {
			$params['m'] = (string) $request['m'];
		}
		if ( $request['author'] > 0 ) {
			$params['author'] = (string) $request['author'];
		}
		if ( ( $request['post_status'] ?? '' ) !== '' ) {
			$params['post_status'] = $request['post_status'];
		}
		if ( ( $request['post_mime_type'] ?? '' ) !== '' ) {
			$params['post_mime_type'] = $request['post_mime_type'];
		}

		return $params;
	}

	/**
	 * @param array<string, string> $get_args
	 * @return array{get: array<string, mixed>, request: array<string, mixed>}
	 */
	/**
	 * Временно подменяет request-контекст на время серверного рендера фрагментов
	 * WP_List_Table в нашем ajax `plathix_list_screen`.
	 *
	 * [internal] (Elementor-скрины в списке): помимо $_GET/$_REQUEST
	 * подменяем $GLOBALS['pagenow']/$GLOBALS['typenow']. Причина: сторонние плагины прячут
	 * свои служебные вложения фильтром на parse_query, гейтящимся по $pagenow/$typenow
	 * (стандартный WP-способ детекции экрана — напр. Elementor Pro screenshots:
	 * `if ('upload.php'!==$pagenow || 'attachment'!==$typenow) return;`). В admin-ajax
	 * $pagenow==='admin-ajax.php', а set_current_screen() эти глобалы НЕ выставляет
	 * ($typenow остаётся пустым) → чужой фильтр выходит по гейту → скрины протекают в наш
	 * рендер. Подделав контекст на окно prepare_items(), даём чужому фильтру сработать САМ,
	 * без знания его meta-ключей. Оба глобала кладутся в тот же снапшот и восстанавливаются
	 * тем же restore_request_globals под finally — при исключении в prepare_items() они не
	 * утекают в остальной admin-ajax запрос.
	 *
	 * $pagenow/$typenow параметризованы по режиму: upload-режим → 'upload.php'/'attachment';
	 * Параметры $pagenow/$typenow generic (CTAN-201/302: во Free зовётся только upload-режимом;
	 * хелперы protected — PRO-контроллер платных типов наследует рендер-механику (shared, р.3);
	 * PRO-fragments передаёт свой контекст).
	 *
	 * @param array<string, mixed> $get_args
	 * @return array{get: array<string, mixed>, request: array<string, mixed>, pagenow: mixed, typenow: mixed}
	 */
	/**
	 * @param array<string, mixed> $get_args
	 * @return array{get: array<string, mixed>, request: array<string, mixed>, pagenow: mixed, typenow: mixed, server_request_uri: string|null, server_php_self: string|null}
	 */
	protected function replace_request_globals(array $get_args, bool $is_trash, string $pagenow, string $typenow): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- snapshot of superglobals for restore in finally; nonce+cap already verified in authorize()
		$saved_state = [
			'get'                => $_GET,
			'request'            => $_REQUEST,
			'pagenow'            => $GLOBALS['pagenow'] ?? null,
			'typenow'            => $GLOBALS['typenow'] ?? null,
			'server_request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : null,
			'server_php_self'    => isset( $_SERVER['PHP_SELF'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['PHP_SELF'] ) ) : null,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		unset( $_GET['plathix_folder'] );
		unset( $_REQUEST['plathix_folder'] );

		foreach ( $get_args as $key => $value ) {
			$_GET[ $key ] = $value;
			$_REQUEST[ $key ] = $value;
		}

		if ( $is_trash ) {
			$_GET['status'] = 'trash';
			$_REQUEST['status'] = 'trash';
		}

		// Подделываем экран медиатеки/CPT-списка для сторонних parse_query-фильтров.
		$GLOBALS['pagenow'] = $pagenow;
		$GLOBALS['typenow'] = $typenow;

		// WP_List_Table::pagination() строит URL пагинации из $_SERVER['REQUEST_URI'].
		// В AJAX-контексте он = admin-ajax.php, что ломает ссылки пагинации ([internal]).
		// [internal]: голый путь без query-строки тоже ломает пагинацию — WP_List_Table::
		// pagination() не читает $_GET напрямую, только REQUEST_URI как базу для add_query_arg,
		// поэтому даже корректный $_GET['plathix_folder'] (выставлен foreach выше) не попадал в
		// pagination-ссылки. Строим query-строку из $get_args (whitelisted-параметр метода) —
		// НЕ из $_GET, который на этот момент всё ещё несёт action/_wpnonce исходного AJAX-
		// запроса (unset/foreach выше точечно меняют отдельные ключи, не очищают $_GET целиком).
		if ( $pagenow === 'upload.php' ) {
			$_SERVER['REQUEST_URI'] = '/wp-admin/upload.php?' . http_build_query( $get_args );
			$_SERVER['PHP_SELF']    = '/wp-admin/upload.php';
		} else {
			$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?' . http_build_query( $get_args );
			$_SERVER['PHP_SELF']    = '/wp-admin/edit.php';
		}

		return $saved_state;
	}

	/**
	 * @param array{get: array<string, mixed>, request: array<string, mixed>, pagenow: mixed, typenow: mixed, server_request_uri: string|null, server_php_self: string|null} $saved_state
	 */
	protected function restore_request_globals(array $saved_state): void {
		$_GET = $saved_state['get'];
		$_REQUEST = $saved_state['request'];
		$GLOBALS['pagenow'] = $saved_state['pagenow'];
		$GLOBALS['typenow'] = $saved_state['typenow'];
		if ( isset( $saved_state['server_request_uri'] ) ) {
			$_SERVER['REQUEST_URI'] = $saved_state['server_request_uri'];
		}
		if ( isset( $saved_state['server_php_self'] ) ) {
			$_SERVER['PHP_SELF'] = $saved_state['server_php_self'];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	protected function json_success(array $payload): void {
		wp_send_json_success( $payload );
	}

	protected function json_error(string $message, int $status = 400): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}

	private function folder_repository(): FolderRepository {
		if ( ! $this->folder_repository instanceof FolderRepository ) {
			$this->folder_repository = new FolderRepository();
		}

		return $this->folder_repository;
	}

	private function ensure_upload_list_table_loaded(): void {
		$this->ensure_list_table_base_loaded();

		if ( ! class_exists( '\WP_Media_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-media-list-table.php';
		}
	}

	private function ensure_list_table_base_loaded(): void {
		if ( ! class_exists( '\WP_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
	}
}
