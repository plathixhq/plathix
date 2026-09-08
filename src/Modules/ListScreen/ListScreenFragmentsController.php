<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Core\FolderQuery;
use Plathix\Core\FolderRepository;
use Plathix\Core\TrashFolder;
use Plathix\Core\TaxonomyResolver;
use Plathix\Core\MultilingualCompat;
use Plathix\Helpers\Sanitize;
use Plathix\Infrastructure\Logger;

class ListScreenFragmentsController
{
	private const AJAX_ACTION = 'plathix_list_screen';

	private ?FolderRepository $folderRepository = null;

	private readonly ListScreenAuthorizer $authorizer;

	public function __construct(?FolderRepository $folderRepository = null, ?ListScreenAuthorizer $authorizer = null) {
		$this->folderRepository = $folderRepository;
		$this->authorizer = $authorizer ?? new ListScreenAuthorizer();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		$request = $this->parseRequest();
		$this->authorizer->authorize( $request );

		try {

			$fragments = $this->renderUploadFragments( $request );
		} catch ( \Throwable $e ) {
			Logger::error( __METHOD__ . ': renderUploadFragments failed.', [], $e );
			$this->jsonError( 'Render failed.', 500 );
			return;
		}

		$this->jsonSuccess( [
			'screenBase' => $request['screen_base'],
			'postType'   => $request['post_type'],
			'folderId'   => $request['folder_id'],
			'url'        => $this->buildCanonicalUrl( $request ),
			'fragments'  => $fragments,
		] );
	}

	/**
	 * Parses read-only list-screen navigation params from $_REQUEST.
	 *
	 * Nonce/cap are verified in authorize() (Nonce::verifyOrDie + current_user_can),
	 * which handle() calls BEFORE any parsed value is used to render or query. No value
	 * here is written to the DB or output unescaped; the sniff's NonceVerification.Recommended
	 * is satisfied by the authorize() gate, hence the per-line ignores below.
	 *
	 * @return array<string, mixed>
	 */
	protected function parseRequest(): array {
		$order_raw = strtoupper( sanitize_key( (string) wp_unslash( $_REQUEST['order'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param; nonce+cap verified in authorize() before use

		// Keys consumed by this handler; everything else passes through to the list table
		// so third-party plugin filters (WooCommerce, CPT taxonomies, etc.) are preserved.
		static $known_keys = [
			'action', 'nonce', '_wpnonce', '_wp_http_referer',
			'screen_base', 'post_type', 'folder_id', 'paged',
			'orderby', 'order', 's', 'm', 'author',
			'post_status', 'post_mime_type',
		];

		static $blocked_extra_keys = [ 'page', 'mode', 'status', 'plathix_folder' ];

		$extra_params = [];
		foreach ( $_REQUEST as $key => $raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav params; nonce+cap verified in authorize() before use
			$safe_key = sanitize_key( (string) $key );
			if (
				$safe_key !== ''
				&& ! in_array( $safe_key, $known_keys, true )
				&& ! in_array( $safe_key, $blocked_extra_keys, true )
			) {

				$value = Sanitize::deepText( wp_unslash( $raw ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified in authorize(); Sanitize::deepText() applies sanitize_text_field() recursively to every leaf

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
	private function renderUploadFragments(array $args): array {
		$this->ensureUploadListTableLoaded();
		set_current_screen( 'upload' );

		$taxonomy  = TaxonomyResolver::fromPostType( 'attachment' );
		$folder_id = (int) $args['folder_id'];
		$is_trash  = $folder_id > 0 && $folder_id === TrashFolder::id( $taxonomy );

		$saved_state = $this->replaceRequestGlobals( $this->buildGetArgs( $args, 'list' ), $is_trash, 'upload.php', 'attachment' );

		$filter = $this->addFragmentFolderFilter( $args['folder_id'], $taxonomy, (string) ( $args['post_type'] ?? 'attachment' ) );

		try {
			$table  = new \WP_Media_List_Table( [ 'screen' => get_current_screen() ] );
			$table->prepare_items();
			return $this->captureFragments( $table );
		} finally {
			remove_filter( 'pre_get_posts', $filter, 5 );
			$this->restoreRequestGlobals( $saved_state );
		}
	}

	/**
	 * @return array{views: string, topNav: string, list: string, bottomNav: string}
	 */
	protected function captureFragments(\WP_List_Table $table): array {
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
	protected function addFragmentFolderFilter(int $folder_id, string $taxonomy, string $post_type): callable {
		$filter = function (\WP_Query $q) use ($folder_id, $taxonomy, $post_type): void {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen instanceof \WP_Screen ) {
				return;
			}

			if ( $screen->id !== 'upload' && $screen->base !== 'edit' ) {
				return;
			}

			FolderQuery::applyParentOrderbyTiebreak( $q );

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

				$q->set( 'tax_query', [] );
				$q->set( 'post_status', 'trash' );
				$q->set( 'post_type', $post_type );
				return;
			}

			$repo = $this->folderRepository();
			if ( $repo->isUncategorizedFolder( $folder_id, $taxonomy ) ) {
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
			MultilingualCompat::suppressForQuery( $q );
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
	protected function buildGetArgs(array $args, string $mode = ''): array {
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
	private function buildCanonicalUrl(array $request): string {
		$params = [];
		$taxonomy = TaxonomyResolver::fromPostType( $request['post_type'] );
		$request_folder_id = (int) $request['folder_id'];
		$is_trash = $request_folder_id > 0 && $request_folder_id === TrashFolder::id( $taxonomy );

		$base = admin_url( 'upload.php' );
		$params['mode'] = 'list';

		$params = array_merge( $params, $this->buildCommonQueryParams( $request ) );

		if ( ! empty( $request['extra_params'] ) && is_array( $request['extra_params'] ) ) {
			$params = array_merge( $params, $request['extra_params'] );
		}

		if ( $is_trash ) {

			$params['attachment-filter'] = 'trash';
		} elseif ( $request_folder_id > 0 ) {

			$params['plathix_folder'] = (string) $request_folder_id;
		}

		return esc_url_raw( add_query_arg( $params, $base ) );
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, string>
	 */
	protected function buildCommonQueryParams(array $request): array {
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
	 * @param array<string, mixed> $get_args
	 * @return array{get: array<string, mixed>, request: array<string, mixed>, pagenow: mixed, typenow: mixed}
	 */

	/**
	 * @param array<string, mixed> $get_args
	 * @return array{get: array<string, mixed>, request: array<string, mixed>, pagenow: mixed, typenow: mixed, server_request_uri: string|null, server_php_self: string|null}
	 */
	protected function replaceRequestGlobals(array $get_args, bool $is_trash, string $pagenow, string $typenow): array {
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

		$GLOBALS['pagenow'] = $pagenow;
		$GLOBALS['typenow'] = $typenow;

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
	protected function restoreRequestGlobals(array $saved_state): void {
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
	protected function jsonSuccess(array $payload): void {
		wp_send_json_success( $payload );
	}

	protected function jsonError(string $message, int $status = 400): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}

	private function folderRepository(): FolderRepository {
		if ( ! $this->folderRepository instanceof FolderRepository ) {
			$this->folderRepository = new FolderRepository();
		}

		return $this->folderRepository;
	}

	private function ensureUploadListTableLoaded(): void {
		$this->ensureListTableBaseLoaded();

		if ( ! class_exists( '\WP_Media_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-media-list-table.php';
		}
	}

	private function ensureListTableBaseLoaded(): void {
		if ( ! class_exists( '\WP_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
	}
}
