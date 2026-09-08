<?php

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- snake_case method names follow the WordPress convention used across this codebase, which conflicts with the PSR-12 base ruleset

namespace Plathix\Core;

use Plathix\Helpers\QueryHelper;
use Plathix\Helpers\Sanitize;
use Plathix\Loader;

final class FolderQuery
{
	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->addAction('pre_get_posts', $this, 'filterListView');
		$this->loader->addFilter('ajax_query_attachments_args', $this, 'filterGridView', 99);
		$this->loader->addFilter('rest_attachment_query', $this, 'filterRestAttachments', 10, 2);
	}

	public function filterListView(\WP_Query $query): void
	{
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Our AJAX fragment renderer (ListScreenFragmentsController) applies folder
		// filtering via addFragmentFolderFilter(). The saved-preference fallback
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

		self::applyParentOrderbyTiebreak($query);

		$query_post_type = $query->get('post_type');
		if ( is_array($query_post_type) ) {
			$query_post_type = reset($query_post_type) ?: 'attachment';
		}

		$post_type = sanitize_key( (string) ( $query_post_type ?: ( $is_media ? 'attachment' : 'post' ) ));
		$request_status = sanitize_key( self::requestScalar( wp_unslash( $_GET['status'] ?? $_GET['post_status'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via requestScalar()+sanitize_key(), not written

		$attachment_filter = sanitize_key( self::requestScalar( wp_unslash( $_GET['attachment-filter'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via requestScalar()+sanitize_key(), not written
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

		$this->applyTaxQuery($query, $folder_id, $taxonomy);
	}

	public static function applyParentOrderbyTiebreak(\WP_Query $query): void
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
	public function filterGridView(array $args): array
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via requestScalar()+sanitize_key(), not written
		$query_status = sanitize_key(
			self::requestScalar( wp_unslash(
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav filter for query building; sanitized via requestScalar()+sanitize_key(), not written
		$attachment_filter = sanitize_key( self::requestScalar( wp_unslash( $_REQUEST['query']['attachment-filter'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $query_status === 'trash' || $attachment_filter === 'trash' ) {
			$args['post_status'] = 'trash';
			unset( $args['tax_query'] );
			$args = MultilingualCompat::suppressForArgs( $args );
			return $args;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav filter for query building; sanitized (absint), not written
		$folder_id = absint(wp_unslash($_REQUEST['query']['plathix_folder'] ?? 0));
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $folder_id <= 0 ) {
			return $args;
		}

		$tax_query = $this->buildTaxQueryForFolder($folder_id, PLATHIX_TAXONOMY);
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query is the only WP-native way to filter WP_Query by taxonomy (the folder feature itself); not a hot request path.
		$args['tax_query'] = QueryHelper::mergeTaxQuerySafely($args['tax_query'] ?? [], $clause);
		$args = MultilingualCompat::suppressForArgs( $args );

		return $args;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function filterRestAttachments(array $args, \WP_REST_Request $request): array
	{

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

		$post_type = sanitize_key( self::requestScalar( $request->get_param('post_type') ?: 'attachment' ) );
		$taxonomy = TaxonomyResolver::fromPostType($post_type);
		if ( ! is_object_in_taxonomy($post_type, $taxonomy) ) {
			return $args;
		}

		$tax_query = $this->buildTaxQueryForFolder($folder_id, $taxonomy);
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query is the only WP-native way to filter WP_Query by taxonomy (the folder feature itself); not a hot request path.
		$args['tax_query'] = QueryHelper::mergeTaxQuerySafely($args['tax_query'] ?? [], $clause);
		$args = MultilingualCompat::suppressForArgs( $args );

		return $args;
	}

	private function applyTaxQuery(\WP_Query $query, int $folder_id, string $taxonomy): void
	{
		$tax_query = $this->buildTaxQueryForFolder($folder_id, $taxonomy);
		$existing = $query->get('tax_query');
		$clause = $tax_query[0] ?? [];
		if ( $clause === [] ) {
			return;
		}

		$query->set('tax_query', QueryHelper::mergeTaxQuerySafely(is_array($existing) ? $existing : [], $clause));
		MultilingualCompat::suppressForQuery($query);
	}

	/** @return array<int, array<string, mixed>> */
	private function buildTaxQueryForFolder(int $folder_id, string $taxonomy): array
	{
		$repo = new FolderRepository();

		if ( $repo->isUncategorizedFolder($folder_id, $taxonomy) ) {
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
	 * @param mixed $value
	 */

	private static function requestScalar(mixed $value): string {
		return Sanitize::toScalarString( $value );
	}
}
