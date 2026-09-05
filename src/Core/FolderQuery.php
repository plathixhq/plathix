<?php

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- snake_case method names follow the WordPress convention used across this codebase, which conflicts with the PSR-12 base ruleset

namespace Plathix\Core;

use Plathix\Helpers\QueryHelper;
use Plathix\Loader;

final class FolderQuery
{
	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->add_action('pre_get_posts', $this, 'filter_list_view');
		$this->loader->add_filter('ajax_query_attachments_args', $this, 'filter_grid_view', 99);
		$this->loader->add_filter('rest_attachment_query', $this, 'filter_rest_attachments', 10, 2);
	}

	public function filter_list_view(\WP_Query $query): void
	{
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Our AJAX fragment renderer (ListScreenFragmentsController) applies folder
		// filtering via add_fragment_folder_filter(). The saved-preference fallback
		// below must not run on top of that — it would override the requested folder
		// with whatever folder the user last visited (e.g. an empty folder).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav filter for query building; sanitized (sanitize_key), not written
		if ( wp_doing_ajax() && ( sanitize_key( (string) wp_unslash( $_REQUEST['action'] ?? '' ) ) === 'plathix_list_screen' ) ) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		$is_media = 'upload' === $screen->id;
		$is_post_list = 'edit' === $screen->base;
		if ( ! $is_media && ! $is_post_list ) {
			return;
		}

		// [internal] ([internal]): orderby=parent нестабилен независимо от того, открыта ли
		// папка Plathix — применяем ДО folder-специфичных ранних return, иначе "все файлы"
		// без выбранной папки останутся без tiebreak.
		self::apply_parent_orderby_tiebreak($query);

		$query_post_type = $query->get('post_type');
		if ( is_array($query_post_type) ) {
			$query_post_type = reset($query_post_type) ?: 'attachment';
		}

		$post_type = sanitize_key( (string) ( $query_post_type ?: ( $is_media ? 'attachment' : 'post' ) ));
		$request_status = sanitize_key( self::request_scalar( wp_unslash( $_GET['status'] ?? $_GET['post_status'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via request_scalar()+sanitize_key(), not written
		// [internal] ([internal]): Корзина в этом не-AJAX list-пути тоже помечается через
		// attachment-filter=trash (тот же ядровой контракт, что [internal] учёл в
		// filter_grid_view), не только status/post_status.
		$attachment_filter = sanitize_key( self::request_scalar( wp_unslash( $_GET['attachment-filter'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via request_scalar()+sanitize_key(), not written
		if ( $request_status === 'trash' || $attachment_filter === 'trash' ) {
			$query->set('post_status', 'trash');
			return;
		}

		$taxonomy = TaxonomyResolver::fromPostType($post_type);
		if ( ! taxonomy_exists($taxonomy) ) {
			return;
		}

		$folder_id = RequestFolderResolver::resolve($post_type, $taxonomy);
		if ( $folder_id <= 0 ) {
			return;
		}

		// Saved preference may be the trash folder (e.g. after restoring a post).
		// Applying a trash-folder tax_query to a non-trash status view returns empty results.
		if ( TrashFolder::id($taxonomy) === $folder_id ) {
			return;
		}

		$this->apply_tax_query($query, $folder_id, $taxonomy);
	}

	/**
	 * [internal] ([internal]): orderby=parent не имеет вторичного tiebreak-ключа в ядре
	 * WordPress. При множестве attachments с одинаковым/нулевым post_parent физический
	 * порядок "равных" строк не детерминирован и может измениться после любой записи в БД
	 * (включая смену папки Plathix, которая post_parent не трогает, но задевает
	 * wp_term_relationships). Добавляем ID тем же order как вторичный ключ, только когда
	 * активна сортировка по parent — остальные orderby-режимы не затрагиваются.
	 *
	 * Static: не использует состояние экземпляра FolderQuery (loader и т.д.), поэтому
	 * вызывается напрямую из ListScreenFragmentsController без добавления DI-зависимости.
	 */
	public static function apply_parent_orderby_tiebreak(\WP_Query $query): void
	{
		if ( $query->get('orderby') !== 'parent' ) {
			return;
		}

		$order_raw = strtoupper( (string) $query->get('order'));
		$order = in_array($order_raw, ['ASC', 'DESC'], true) ? $order_raw : 'DESC';

		$query->set('orderby', ['parent' => $order, 'ID' => $order]);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function filter_grid_view(array $args): array
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via request_scalar()+sanitize_key(), not written
		$query_status = sanitize_key(
			self::request_scalar( wp_unslash(
				$_REQUEST['query']['status']
				?? $_REQUEST['query']['post_status']
				?? $_REQUEST['status']
				?? $_REQUEST['post_status']
				?? $args['status']
				?? $args['post_status']
				?? ''
			) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// [internal] ([internal]): WP media grid помечает Корзину через
		// query[attachment-filter]=trash, НЕ через status/post_status (тот же ядровой
		// контракт, что уже учтён в ListScreenFragmentsController для AJAX-list-пути).
		// Без этой проверки запрос проваливается в folder_id-ветку ниже и произвольная
		// "открытая" папка (query[plathix_folder]) подмешивается поверх корзины — живой
		// AJAX-лог подтвердил оба параметра в одном запросе одновременно.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via request_scalar()+sanitize_key(), not written
		$attachment_filter = sanitize_key( self::request_scalar( wp_unslash( $_REQUEST['query']['attachment-filter'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $query_status === 'trash' || $attachment_filter === 'trash' ) {
			$args['post_status'] = 'trash';
			unset( $args['tax_query'] );
			$args = MultilingualCompat::suppress_for_args( $args );
			return $args;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav filter for query building; sanitized (absint), not written
		$folder_id = absint(wp_unslash($_REQUEST['query']['plathix_folder'] ?? 0));
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $folder_id <= 0 ) {
			return $args;
		}

		$tax_query = $this->build_tax_query_for_folder($folder_id, PLATHIX_TAXONOMY);
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query is the only WP-native way to filter WP_Query by taxonomy (the folder feature itself); not a hot request path.
		$args['tax_query'] = QueryHelper::merge_tax_query_safely($args['tax_query'] ?? [], $clause);
		$args = MultilingualCompat::suppress_for_args( $args );

		return $args;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function filter_rest_attachments(array $args, \WP_REST_Request $request): array
	{
		// WP core REST-схема параметра `status` для /wp/v2/media — `type: array`
		// (`items.enum: [inherit, private, trash]`, подтверждено живым OPTIONS-запросом):
		// даже одиночное `?status=trash` в query string WP отдаёт из get_param() как
		// массив `['trash']`. Без reset() PHP кастует массив к строке внутри `(string)`
		// и кидает "Array to string conversion" прямо в тело REST-ответа (воспроизведено
		// живьём: GET /wp/v2/media?status=trash&context=edit&per_page=100 —
		// warning + пустой `[]`, тот же паттерн уже пофикшен для $post_type строкой выше
		// по файлу, [internal], но не был применён здесь). `post_status` не входит в
		// REST-схему media вообще — это сырой query var, всегда скаляр.
		$status_param = $request->get_param('status');
		if ( is_array( $status_param ) ) {
			$status_param = reset( $status_param ) ?: null;
		}
		$request_status = sanitize_key( (string) ( $status_param ?: $request->get_param('post_status') ?: '' ) );
		if ( $request_status === 'trash' ) {
			$args['post_status'] = 'trash';
			return $args;
		}

		$folder_id = absint($request->get_param('plathix_folder'));
		if ( $folder_id <= 0 ) {
			return $args;
		}

		$post_type = sanitize_key( self::request_scalar( $request->get_param('post_type') ?: 'attachment' ) );
		$taxonomy = TaxonomyResolver::fromPostType($post_type);
		if ( ! is_object_in_taxonomy($post_type, $taxonomy) ) {
			return $args;
		}

		$tax_query = $this->build_tax_query_for_folder($folder_id, $taxonomy);
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query is the only WP-native way to filter WP_Query by taxonomy (the folder feature itself); not a hot request path.
		$args['tax_query'] = QueryHelper::merge_tax_query_safely($args['tax_query'] ?? [], $clause);
		$args = MultilingualCompat::suppress_for_args( $args );

		return $args;
	}

	private function apply_tax_query(\WP_Query $query, int $folder_id, string $taxonomy): void
	{
		$tax_query = $this->build_tax_query_for_folder($folder_id, $taxonomy);
		$existing = $query->get('tax_query');
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return;
		}

		$query->set('tax_query', QueryHelper::merge_tax_query_safely(is_array($existing) ? $existing : [], $clause));
		MultilingualCompat::suppress_for_query($query);
	}

	/** @return array<int, array<string, mixed>> */
	private function build_tax_query_for_folder(int $folder_id, string $taxonomy): array
	{
		$repo = new FolderRepository();

		if ( $repo->is_uncategorized_folder($folder_id, $taxonomy) ) {
			return [
				[
					'taxonomy' => $taxonomy,
					'operator' => 'NOT EXISTS',
				],
			];
		}

		return [
			[
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => [ $folder_id ],
				'include_children' => false,
			],
		];
	}

	/**
	 * Приводит значение из $_GET/$_REQUEST к скаляру перед `sanitize_key()`/(string)-кастом.
	 * Клиент может прислать эти ключи как массив (`?status[]=x`) — без этой проверки PHP
	 * кидает `Array to string conversion` warning и '' некорректно заменяется на "Array"
	 * ([internal]).
	 *
	 * @param mixed $value
	 */
	private static function request_scalar(mixed $value): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
