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
		$user_level   = AccessResolver::forCurrentUser();
		$pt_obj       = get_post_type_object( $post_type );
		$label_single = $pt_obj?->labels->singular_name ?? __( 'File', 'plathix' );
		$label_plural = $pt_obj?->labels->name ?? __( 'Files', 'plathix' );

		$auto_lazy_at            = FolderTreeBootstrapStrategy::threshold();
		$folder_count            = $this->folders->countAll( $taxonomy );
		$defer_folders_bootstrap = FolderTreeBootstrapStrategy::shouldDefer( $folder_count );

		$bootstrap_loaded_parents = [ 0 ];

		if ( $defer_folders_bootstrap ) {
			$bootstrap_folders = $this->assembler->build( $taxonomy, $open_id, $bootstrap_loaded_parents );
		} else {
			$folders           = $this->folders->getAllCached( $taxonomy );
			$bootstrap_folders = $this->normalizeFolderPayload( $folders );
		}

		$data = [
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'restUrl'    => esc_url_raw( trailingslashit( rest_url( 'plathix/' . RestController::API_VERSION ) ) ),

			'restUrlFallback' => RestController::restRouteFallbackBase(),
			'wpMediaUrl' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			'nonce'      => Nonce::create(),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'postType' => $post_type,
			'taxonomy' => $taxonomy,
			'folders' => $bootstrap_folders,
			'openId' => $open_id,
			'openFolderId' => $open_id,
			'favorites' => Preferences::getFavorites( get_current_user_id(), $post_type ),
			'depthLimit' => (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH ),
			'userLevel' => $user_level->value,
			'caps' => RestController::getCapMapForJs( $post_type ),
			'imageSizes' => self::getImageSizesCached(),
			'lightboxZ' => (int) apply_filters( 'plathix/ui/z_index_lightbox', 160001 ),
			'svgSupport' => Features::isEnabled( 'svg' ),
			'postId' => (int) ( get_the_ID() ?: 0 ),

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

			],
			'screenBase' => $screen_context,
			'screenKind' => $screen_kind,
			'mediaMode' => $media_mode,
			'filterStrategy' => $filter_strategy,

			'isForeignContext' => RequestContext::isPageBuilderRequest(),
			'infiniteScroll' => (bool) get_option( 'plathix_infinite_scroll', false ),

			'dnd' => Features::isEnabled( 'dnd' ),
			'uploadSync' => Features::isEnabled( 'upload_sync' ),

			'mediaModalOnly' => $screen_kind === 'modal',
			'isStaticLibraryScreen' => $screen_kind === 'static',
			'bulkSafeMode' => (bool) get_option( 'plathix_bulk_safe_mode', true ),
		];

		$data = (array) apply_filters( 'plathix/sidebar/config', $data );

		return $this->applySidebarOverrides( $data );
	}

	/**
	 * @param array<int, mixed> $folders
	 * @return array<int, array<string, mixed>>
	 */

	private function normalizeFolderPayload(array $folders): array {
		$has_children_map = [];
		foreach ( $folders as $folder ) {
			$parent_id = (int) ( is_object( $folder ) ? ( $folder->parentId ?? 0 ) : ( $folder['parentId'] ?? 0 ) );
			if ( $parent_id > 0 ) {
				$has_children_map[ $parent_id ] = true;
			}
		}

		return array_map(
			static function (mixed $folder) use ($has_children_map): array {
				$data = is_object( $folder ) && method_exists( $folder, 'toArray' )
					? $folder->toArray()
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
	private function applySidebarOverrides(array $data): array {
		/**
		 * Add CSS classes to the sidebar root element.
		 *
		 * @param string[] $classes
		 */
		$skin_classes = (array) apply_filters( 'plathix/sidebar/root_classes', [] );

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
		 * @param array[] $actions
		 */

		$toolbar_extra = (array) apply_filters( 'plathix/sidebar/toolbar_extra', [] );
		if ( ! empty( $toolbar_extra ) ) {
			$data['toolbarExtra'] = array_values( array_map(
				function (array $item): array {
					$descriptor = [
						'id'    => sanitize_key( (string) ( $item['id'] ?? '' ) ),
						'title' => esc_attr( (string) ( $item['title'] ?? '' ) ),
						'icon'  => $this->sanitizeIconHtml( (string) ( $item['icon'] ?? '' ) ),
					];

					$active = sanitize_html_class( (string) ( $item['active'] ?? '' ) );
					if ( $active !== '' ) {
						$descriptor['active'] = $active;
					}

					return $descriptor;
				},
				self::sortToolbarExtra( $toolbar_extra )
			) );
		}

		return $data;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */

	private static function sortToolbarExtra(array $items): array {
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

	private function sanitizeIconHtml(string $html): string {
		return \Plathix\Helpers\Sanitize::iconMarkup( $html );
	}

	/** @return string[] */
	private static function getImageSizesCached(): array {
		static $sizes = null;
		if ( $sizes === null ) {
			$sizes = array_values( array_unique( array_merge( [ 'thumbnail', 'medium', 'large', 'full' ], array_values( get_intermediate_image_sizes() ) ) ) );
		}
		return $sizes;
	}
}
