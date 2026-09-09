<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderMutationService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\RateLimiter;

final class FolderBatchController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $folders,
		private readonly FolderTreeService $tree,
		private readonly RateLimiter $rateLimiter,
		private readonly FolderMutationService $mutations,
	) {
	}

	public function batchCreateFolders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {

		if ( ! $this->rateLimiter->attempt( 'batch_create_folders', get_current_user_id(), max: 20, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->requestTaxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->runOptionalOverride(
			$runner_override,
			fn (): array => $this->runBatchCreateFolders( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->recordAudit(
			'folders_created_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['created'] ?? [] ) ),
				'summary'    => sprintf( 'Created %d folders', count( (array) ( $result['created'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'  => $taxonomy,
					'post_type'  => (string) $request->get_param( 'post_type' ),
					'created_ids' => array_column( (array) ( $result['created'] ?? [] ), 'id' ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	public function batchDeleteFolders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {

		if ( ! $this->rateLimiter->attempt( 'batch_delete_folders', get_current_user_id(), max: 10, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy    = $this->requestTaxonomy( $request );
		$ids         = $this->sanitizeIdsParam( $request->get_param( 'ids' ) );
		$on_children = in_array( (string) $request->get_param( 'on_children' ), [ 'reattach', 'delete' ], true )
			? (string) $request->get_param( 'on_children' )
			: FolderTreeService::DEFAULT_ON_CHILDREN;

		if ( $ids === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->runOptionalOverride(
			$runner_override,
			fn (): array => $this->runBatchDeleteFolders( $ids, $taxonomy, $on_children ),
			$ids,
			$taxonomy,
			$on_children
		);

		$this->recordAudit(
			'folders_deleted_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['deleted'] ?? [] ) ),
				'summary'    => sprintf( 'Deleted %d folders', count( (array) ( $result['deleted'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'     => $taxonomy,
					'post_type'     => (string) $request->get_param( 'post_type' ),
					'on_children'   => $on_children,
					'deleted_ids'   => (array) ( $result['deleted'] ?? [] ),
					'deleted_names' => (array) ( $result['deleted_names'] ?? [] ),
				],
			],
			$audit_runner
		);

		unset( $result['deleted_names'] );

		return new \WP_REST_Response( $result );
	}

	public function batchUpdateFolders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {

		if ( ! $this->rateLimiter->attempt( 'batch_update_folders', get_current_user_id(), max: 20, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->requestTaxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->runOptionalOverride(
			$runner_override,
			fn (): array => $this->runBatchUpdateFolders( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->recordAudit(
			'folders_updated_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['updated'] ?? [] ) ),
				'summary'    => sprintf( 'Updated %d folders', count( (array) ( $result['updated'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'   => $taxonomy,
					'post_type'   => (string) $request->get_param( 'post_type' ),
					'updated_ids' => (array) ( $result['updated'] ?? [] ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	public function recountFolders(\WP_REST_Request $request, ?\Closure $runner_override = null): \WP_REST_Response {
		$taxonomy = $this->requestTaxonomy( $request );
		$result   = $this->runOptionalOverride(
			$runner_override,
			fn (): array => $this->runRecountFolders( $taxonomy ),
			$taxonomy
		);

		return new \WP_REST_Response(
			[
				'success'  => (bool) ( $result['success'] ?? false ),
				'taxonomy' => $taxonomy,
			]
		);
	}

	public function reorderTree(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {

		if ( ! $this->rateLimiter->attempt( 'reorder_tree', get_current_user_id(), max: 10, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->requestTaxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->runOptionalOverride(
			$runner_override,
			fn (): array => $this->runReorderTree( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->recordAudit(
			'folders_reordered_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['reordered'] ?? [] ) ),
				'summary'    => sprintf( 'Reordered %d folders', count( (array) ( $result['reordered'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'  => $taxonomy,
					'post_type'  => (string) $request->get_param( 'post_type' ),
					'reordered' => (array) ( $result['reordered'] ?? [] ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{created: list<array{id: int, name: string, parentId: int}>, failed: list<array{name: string, parentId: int, code: string, message: string}|array{name: string, message: string}>}
	 */
	private function runBatchCreateFolders(array $items, string $taxonomy): array {
		$created = [];
		$failed  = [];

		foreach ( $items as $item ) {
			$name      = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$parent_id = absint( $item['parent_id'] ?? 0 );
			if ( $name === '' ) {
				$failed[] = RestFailureValues::emptyFolderName();
				continue;
			}

			$result = $this->tree->create( $name, $parent_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				/**
				 * @var \WP_Error $result
				 */

				$failed[] = RestFailureValues::batchCreate( $name, $parent_id, $result );
				continue;
			}
			/**
			 * @var int $result
			 */


			$created[] = RestFailureValues::batchCreatedRow( (int) $result, $name, $parent_id );
		}

		return [ 'created' => $created, 'failed' => $failed ];
	}

	/**
	 * @param array<int, int|string> $ids
	 * @return array{deleted: list<int>, deleted_names: list<string>, failed: list<array{id: int, message: string}>}
	 */
	private function runBatchDeleteFolders(array $ids, string $taxonomy, string $on_children): array {
		$deleted       = [];
		$deleted_names = [];
		$failed        = [];

		foreach ( $ids as $id ) {
			$folder_id = (int) $id;
			if ( $folder_id <= 0 ) {
				$failed[] = RestFailureValues::invalidFolder( $folder_id );
				continue;
			}

			$term = $this->repository->getById( $folder_id, $taxonomy );
			$name = $term instanceof \WP_Term ? $term->name : '';

			if ( $this->tree->deleteRecursive( $folder_id, $taxonomy, $on_children ) ) {
				$deleted[]       = $folder_id;
				$deleted_names[] = $name;
				continue;
			}

			$failed[] = RestFailureValues::deleteFolder( $folder_id );
		}

		return [ 'deleted' => $deleted, 'deleted_names' => $deleted_names, 'failed' => $failed ];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{updated: list<int>, failed: list<array{id: int, message: string}|array{id: int, code: string, message: string}>}
	 */

	private function runBatchUpdateFolders(array $items, string $taxonomy): array {
		$updated = [];
		$failed  = [];

		foreach ( $items as $item ) {
			$id = absint( $item['id'] ?? 0 );
			if ( $id <= 0 ) {
				$failed[] = RestFailureValues::invalidFolder();
				continue;
			}

			$term = $this->repository->getById( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				$failed[] = RestFailureValues::missingFolder( $id );
				continue;
			}

			$error = $this->mutations->applyChanges( $id, $item, $taxonomy );
			if ( is_wp_error( $error ) ) {
				/**
				 * @var \WP_Error $error
				 */

				$failed[] = RestFailureValues::wpError( $id, $error );
				continue;
			}

			$updated[] = $id;
		}

		return [ 'updated' => $updated, 'failed' => $failed ];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{reordered: list<array{id: int, parent_id: int, position: int}>, failed: list<array{id: int, message: string}|array{id: int, code: string, message: string}>}
	 */
	private function runReorderTree(array $items, string $taxonomy): array {
		$reordered = [];
		$failed    = [];

		foreach ( $items as $item ) {
			$id        = absint( $item['id'] ?? 0 );
			$parent_id = absint( $item['parent_id'] ?? 0 );
			$position  = absint( $item['position'] ?? 0 );

			if ( $id <= 0 ) {
				$failed[] = RestFailureValues::invalidFolder();
				continue;
			}

			$moved = $this->tree->move( $id, $parent_id, $taxonomy );
			if ( is_wp_error( $moved ) ) {
				/**
				 * @var \WP_Error $moved
				 */

				$failed[] = RestFailureValues::wpError( $id, $moved );
				continue;
			}

			$ordered = $this->tree->setOrder( $id, $position, $taxonomy );
			if ( is_wp_error( $ordered ) ) {
				/**
				 * @var \WP_Error $ordered
				 */

				$failed[] = RestFailureValues::wpError( $id, $ordered );
				continue;
			}

			$reordered[] = [
				'id'        => $id,
				'parent_id' => $parent_id,
				'position'  => $position,
			];
		}

		return [ 'reordered' => $reordered, 'failed' => $failed ];
	}

	/**
	 * @return array{success: bool}
	 */
	private function runRecountFolders(string $taxonomy): array {
		$this->folders->invalidate( $taxonomy );
		return [ 'success' => true ];
	}
}
