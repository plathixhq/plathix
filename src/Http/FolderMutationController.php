<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderMutationService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\RateLimiter;

final class FolderMutationController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderTreeService $tree,
		private readonly RateLimiter $rateLimiter,
		private readonly FolderMutationService $mutations,
	) {
	}

	public function createFolder(\WP_REST_Request $request): \WP_REST_Response {
		if ( ! $this->rateLimiter->attemptAction( 'create_folder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are creating folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$taxonomy = $this->requestTaxonomy( $request );
		$id       = $this->tree->create( (string) $request->get_param( 'name' ), (int) $request->get_param( 'parent_id' ), $taxonomy );

		if ( is_wp_error( $id ) ) {
			/**
			 * @var \WP_Error $id
			 */

			return $this->errorResponse( $id, 422 );
		}
		/**
		 * @var int $id
		 */


		do_action( 'plathix/audit/record',
			'folder_created',
			[
				'objectType' => 'folder',
				'objectId'   => (int) $id,
				'targetType' => 'folder',
				'targetId'   => (int) $request->get_param( 'parent_id' ),
				'summary'     => sprintf( 'Created folder "%s"', (string) $request->get_param( 'name' ) ),
				'context'     => [
					'taxonomy'  => $taxonomy,
					'post_type' => (string) $request->get_param( 'post_type' ),
					'name'      => (string) $request->get_param( 'name' ),
				],
			]
		);

		return new \WP_REST_Response( [ 'id' => $id ] );
	}

	public function updateFolder(\WP_REST_Request $request): \WP_REST_Response {

		if ( ! $this->rateLimiter->attemptAction( 'update_folder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are changing folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->requestTaxonomy( $request );
		$term     = $this->repository->getById( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( $id > 0 && $name !== '' ) {
			$error = $this->mutations->applyChanges( $id, [ 'name' => $name ], $taxonomy );
			if ( $error instanceof \WP_Error ) {
				return $this->errorResponse( $error, 422 );
			}

			do_action( 'plathix/audit/record',
				'folder_renamed',
				[
					'objectType' => 'folder',
					'objectId'   => $id,
					'summary'     => sprintf( 'Renamed folder to "%s"', $name ),
					'context'     => [
						'taxonomy'  => $taxonomy,
						'post_type' => (string) $request->get_param( 'post_type' ),
						'name'      => $name,
					],
				]
			);

			$ignored_fields = array_values( array_filter(
				[ 'parent_id', 'position', 'color' ],
				static fn (string $field): bool => $request->has_param( $field )
			) );

			$response_data = [ 'success' => true ];
			if ( $ignored_fields !== [] ) {
				$response_data['ignored_fields'] = $ignored_fields;
			}

			return new \WP_REST_Response( $response_data );
		}

		$changes        = [];
		$new_parent     = null;
		if ( $request->has_param( 'parent_id' ) ) {
			$candidate = absint( $request->get_param( 'parent_id' ) );
			if ( $candidate !== (int) $term->parent ) {
				$changes['parent_id'] = $candidate;
				$new_parent           = $candidate;
			}
		}
		if ( $request->has_param( 'position' ) ) {
			$changes['position'] = absint( $request->get_param( 'position' ) );
		}
		if ( $request->has_param( 'color' ) ) {
			$changes['color'] = (string) $request->get_param( 'color' );
		}

		$error = $this->mutations->applyChanges( $id, $changes, $taxonomy );
		if ( $error instanceof \WP_Error ) {
			return $this->errorResponse( $error, 409 );
		}

		if ( $new_parent !== null ) {
			do_action( 'plathix/audit/record',
				'folder_moved',
				[
					'objectType' => 'folder',
					'objectId'   => $id,
					'targetType' => 'folder',
					'targetId'   => $new_parent,
					'summary'    => sprintf( 'Moved folder "%s"', $term->name ),
					'context'    => [
						'taxonomy'   => $taxonomy,
						'post_type'   => (string) $request->get_param( 'post_type' ),
						'from_parent' => (int) $term->parent,
						'to_parent'   => $new_parent,
					],
				]
			);
		}

		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public function deleteFolder(\WP_REST_Request $request): \WP_REST_Response {

		if ( ! $this->rateLimiter->attemptAction( 'deleteFolder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are deleting folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->requestTaxonomy( $request );
		$term     = $this->repository->getById( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		$on_children = in_array( (string) $request->get_param( 'on_children' ), [ 'reattach', 'delete' ], true )
			? (string) $request->get_param( 'on_children' )
			: FolderTreeService::DEFAULT_ON_CHILDREN;

		$ok = $this->tree->deleteRecursive( $id, $taxonomy, $on_children );

		if ( $ok ) {
			do_action( 'plathix/audit/record',
				'folder_deleted',
				[
					'objectType' => 'folder',
					'objectId'   => $id,
					'summary'   => sprintf( 'Deleted folder "%s"', $term->name ),
					'context'   => [
						'taxonomy'   => $taxonomy,
						'post_type'   => (string) $request->get_param( 'post_type' ),
						'name'       => $term->name,
						'on_children' => $on_children,
					],
				]
			);
		}

		return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 500 );
	}
}
