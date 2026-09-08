<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Core\FolderColumnContract;
use Plathix\Core\TaxonomyResolver;

class FolderColumn
{
	public const COLUMN_KEY = FolderColumnContract::COLUMN_KEY;
	private const LINK_CLASS = 'plathix-folder-link';

	/** @var string[] */
	private array $post_types;

	public function register(): void {
		$this->post_types = [ 'attachment' ];


		if ( in_array('attachment', $this->post_types, true) ) {
			add_filter('manage_upload_columns', [ $this, 'addMediaColumn' ]);
			add_action('manage_media_custom_column', [ $this, 'renderMediaColumn' ], 10, 2);
		}

	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addMediaColumn(array $columns): array {
		return $this->insertAfterTitle($columns);
	}

	public function renderMediaColumn(string $column, int $post_id): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}
		$this->render($post_id, 'attachment');
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	protected function insertAfterTitle(array $columns): array {
		$result = [];
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			// 'title' = post tables, 'media' = WP_Media_List_Table
			if ( 'title' === $key || 'media' === $key ) {
				$result[ self::COLUMN_KEY ] = __('Folder', 'plathix');
			}
		}

		// If title column doesn't exist, append at the end
		if ( ! array_key_exists(self::COLUMN_KEY, $result) ) {
			$result[ self::COLUMN_KEY ] = __('Folder', 'plathix');
		}

		return $result;
	}

	protected function render(int $post_id, string $post_type): void {
		$taxonomy = TaxonomyResolver::fromPostType($post_type);
		$terms    = get_the_terms($post_id, $taxonomy);

		if ( empty($terms) || is_wp_error($terms) ) {
			echo '<span class="plathix-folder-column__empty">—</span>';
			return;
		}

		$links = [];
		foreach ( $terms as $term ) {
			$url = $this->filterUrl($post_type, (int) $term->term_id);
			$links[] = sprintf(
				'<a href="%s" class="%s" data-plathix-folder-id="%d">%s</a>',
				esc_url($url),
				esc_attr(self::LINK_CLASS),
				(int) $term->term_id,
				esc_html($term->name)
			);
		}

		echo implode(', ', $links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is built with esc_url/esc_attr/esc_html above
	}

	protected function filterUrl(string $post_type, int $folder_id): string {

		$context = ListScreenQueryContext::fromRequest();

		unset( $context['s'] );

		if ( 'attachment' === $post_type ) {
			// Carry stable display prefs from the current screen so the link works
			// correctly even if JS is unavailable.
			unset( $context['post_status'] );

			$args = [ 'plathix_folder' => $folder_id ] + $context;
			$args['mode'] = sanitize_key( (string) wp_unslash( $_GET['mode'] ?? 'list' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param to build admin list-screen link via add_query_arg; sanitized (sanitize_key), output esc_url'd
			return add_query_arg( $args, admin_url( 'upload.php' ) );
		}

		return add_query_arg( [ 'plathix_folder' => $folder_id ] + $context, admin_url( 'upload.php' ) );
	}
}
