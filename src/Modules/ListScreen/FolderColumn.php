<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Core\FolderColumnContract;
use Plathix\Core\TaxonomyResolver;

/**
 * @api EXTENSION POINT (CEC-201): объявлен в {@see \Plathix\DevContracts\PublicContractRegistry},
 * PRO-колонка платных типов наследует `insert_after_title`/`render` и переопределяет
 * `filter_url`. Behavior_test — tests/FolderColumnTest.php. Не возвращать `final`.
 *
 * CTAN-403: класс открыт для наследования — PRO-колонка платных типов переиспользует
 * render/insert_after_title (generic по термам), принося свои хуки и edit-ссылки.
 */
class FolderColumn
{
	public const COLUMN_KEY = FolderColumnContract::COLUMN_KEY;
	private const LINK_CLASS = 'plathix-folder-link';

	/** @var string[] */
	private array $post_types;

	public function register(): void {
		$this->post_types = [ 'attachment' ]; // CTAN-201: Free-колонка только media; CPT-колонку регистрирует PRO

		if ( in_array('attachment', $this->post_types, true) ) {
			add_filter('manage_upload_columns', [ $this, 'add_media_column' ]);
			add_action('manage_media_custom_column', [ $this, 'render_media_column' ], 10, 2);
		}

		// CTAN-201: регистрация CPT-колонок удалена — Free-колонка только media;
		// колонки списков записей регистрирует PRO своим модулем.
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_media_column(array $columns): array {
		return $this->insert_after_title($columns);
	}

	public function render_media_column(string $column, int $post_id): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}
		$this->render($post_id, 'attachment');
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	protected function insert_after_title(array $columns): array {
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
			$url = $this->filter_url($post_type, (int) $term->term_id);
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

	protected function filter_url(string $post_type, int $folder_id): string {
		// [internal]: query-контекст (orderby/order/s/m/author/post_mime_type/post_status)
		// теперь читается через единый ListScreenQueryContext, вместо независимого
		// allowlist'а этого метода (см. класс — устраняет расхождение с SearchSortFields/
		// build_get_args, которое уже дважды приводило к потере контекста, [internal]/#239).
		// mode/post_type/plathix_folder остаются локальной логикой этого метода — они не
		// про query-контекст сортировки/фильтров, а про display-режим/адресацию ссылки.
		$context = ListScreenQueryContext::fromRequest();
		// 's' (активный поисковый запрос) намеренно не переносится в folder-навигацию —
		// spec этого пакета не заявляет это как контракт (FolderColumn раньше это поле не
		// нёс), и продуктово переход по папке не должен молча тащить за собой чужой поиск.
		unset( $context['s'] );

		if ( 'attachment' === $post_type ) {
			// Carry stable display prefs from the current screen so the link works
			// correctly even if JS is unavailable.
			unset( $context['post_status'] ); // не нёсся раньше для attachment-ветки, сохраняем асимметрию
			$args = [ 'plathix_folder' => $folder_id ] + $context;
			$args['mode'] = sanitize_key( (string) wp_unslash( $_GET['mode'] ?? 'list' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param to build admin list-screen link via add_query_arg; sanitized (sanitize_key), output esc_url'd
			return add_query_arg( $args, admin_url( 'upload.php' ) );
		}

		// CTAN-201: ветка ссылок на edit.php удалена — Free-колонка живёт только на media
		// (post_types = ['attachment'] в register()); CPT-колонку и её ссылки рендерит PRO.
		return add_query_arg( [ 'plathix_folder' => $folder_id ] + $context, admin_url( 'upload.php' ) );
	}
}
