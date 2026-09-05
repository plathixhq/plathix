<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\MediaDeleteService;
use Plathix\Core\MediaMoveOrchestrator;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\RateLimiter;

final class MediaController
{
	use RestControllerHelpers;

	private ?MediaDeleteService $media_delete_service = null;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderAssignmentService $assignment,
		private readonly RateLimiter $rate_limiter
	) {
	}

	public function bulk_trash_media(\WP_REST_Request $request): \WP_REST_Response {
		// [internal]: по аналогии с unassign_items (60/60) — прямой сосед в этом классе,
		// сравнимая тяжесть (term-relation/status мутация на N элементов, не рекурсия по дереву).
		if ( ! $this->rate_limiter->attempt( 'bulk_trash_media', get_current_user_id(), max: 60, window: 60 ) ) {
			return new \WP_REST_Response( [ 'code' => 'rate_limit', 'message' => __( 'Too many requests. Please try again later.', 'plathix' ) ], 429 );
		}

		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		if ( $post_type !== 'attachment' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Media trash is only available for attachments.', 'plathix' ) ], 422 );
		}

		$ids = $this->sanitize_ids_param( $request->get_param( 'ids' ) );
		if ( empty( $ids ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		$taxonomy = Taxonomy::taxonomy_for_post_type( 'attachment' );
		$result   = $this->media_delete_service()->bulk_trash( $ids, $taxonomy )->toArray();

		do_action( 'plathix/audit/record',
			'media_trashed_bulk',
			[
				'objectType' => 'attachment',
				'itemsCount' => count( $ids ),
				'summary'     => sprintf( 'Moved %d media items to trash', count( $ids ) ),
				'context'     => [
					'taxonomy' => $taxonomy,
					'item_ids' => array_slice( $ids, 0, 20 ),
					'result'   => $result,
				],
			]
		);

		return new \WP_REST_Response( $result );
	}

	public function bulk_restore_media(\WP_REST_Request $request): \WP_REST_Response {
		// [internal]: симметрично bulk_trash_media (60/60) — тот же класс тяжести.
		if ( ! $this->rate_limiter->attempt( 'bulk_restore_media', get_current_user_id(), max: 60, window: 60 ) ) {
			return new \WP_REST_Response( [ 'code' => 'rate_limit', 'message' => __( 'Too many requests. Please try again later.', 'plathix' ) ], 429 );
		}

		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		if ( $post_type !== 'attachment' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Media restore is only available for attachments.', 'plathix' ) ], 422 );
		}

		$ids = $this->sanitize_ids_param( $request->get_param( 'ids' ) );
		if ( empty( $ids ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		$taxonomy         = Taxonomy::taxonomy_for_post_type( 'attachment' );
		$target_folder_id = absint( $request->get_param( 'target_folder_id' ) );
		$result           = $this->media_delete_service()->bulk_restore( $ids, $target_folder_id, $taxonomy )->toArray();

		do_action( 'plathix/audit/record',
			'media_restored_bulk',
			[
				'objectType' => 'attachment',
				'targetType' => 'folder',
				'targetId'   => $target_folder_id,
				'itemsCount' => count( $ids ),
				'summary'     => sprintf( 'Restored %d media items', count( $ids ) ),
				'context'     => [
					'taxonomy'  => $taxonomy,
					'item_ids'  => array_slice( $ids, 0, 20 ),
					'result'    => $result,
				],
			]
		);

		return new \WP_REST_Response( $result );
	}


	public function set_items(\WP_REST_Request $request): \WP_REST_Response {
		return $this->move_items( $request );
	}

	public function move_items(\WP_REST_Request $request): \WP_REST_Response {
		if ( ! $this->rate_limiter->attempt( 'move_items_bulk', get_current_user_id(), max: 120, window: 60 ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'Too many requests. Please try again later.', 'plathix' ),
				],
				429
			);
		}

		$folder_id = absint( $request->get_param( 'id' ) );
		$taxonomy  = $this->request_taxonomy( $request );
		$term      = $this->repository->get_by_id( $folder_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		$raw_ids = $request->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) || $raw_ids === [] ) {
			$raw_ids = $request->get_param( 'item_ids' );
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $raw_ids ) ) );
		if ( $ids === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		// Trash-aware маршрутизация ([internal]/#235) вынесена в MediaMoveOrchestrator —
		// единственный источник истины, переиспользуемый также AjaxRouter/AssignmentsApi
		// ([internal], [internal]).
		$result   = MediaMoveOrchestrator::route( $ids, $folder_id, $taxonomy );
		$restored = $result->restored;
		$trashed  = $result->trashed;

		do_action( 'plathix/audit/record',
			'items_moved_bulk',
			[
				'objectType' => 'folder',
				'objectId'   => $folder_id,
				'itemsCount' => count( $ids ),
				'summary'     => sprintf( 'Moved %d items', count( $ids ) ),
				'context'     => [
					'taxonomy'  => $taxonomy,
					'post_type' => (string) $request->get_param( 'post_type' ),
					'item_ids'  => array_slice( $ids, 0, 20 ),
					'result'    => [
						'moved'    => $result->moved,
						'skipped'  => $result->skipped,
						'failed'   => count( $result->failed ),
						'restored' => count( $restored ),
						'trashed'  => count( $trashed ),
					],
				],
			]
		);

		return new \WP_REST_Response( $result->toArray() );
	}

	public function unassign_items(\WP_REST_Request $request): \WP_REST_Response {
		if ( ! $this->rate_limiter->attempt( 'unassign_items', get_current_user_id(), max: 60, window: 60 ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'Too many requests. Please try again later.', 'plathix' ),
				],
				429
			);
		}

		$taxonomy = $this->request_taxonomy( $request );
		$ids      = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'item_ids' ) ) ) );
		if ( $ids === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		// Per-item авторизация + инвалидация живут в Core ([internal], M2);
		// раньше mutation была inline здесь без проверки прав — та же IDOR-дыра, что H1.
		$result     = $this->assignment->unassign_items( $ids, $taxonomy );
		$unassigned = $result['unassigned'];

		do_action( 'plathix/audit/record',
			'items_unassigned',
			[
				'objectType' => 'item',
				'objectId'   => 0,
				'itemsCount' => count( $ids ),
				'summary'     => sprintf( 'Unassigned %d items from all folders', $unassigned ),
				'context'     => [
					'taxonomy'  => $taxonomy,
					'post_type' => (string) $request->get_param( 'post_type' ),
					'item_ids'  => array_slice( $ids, 0, 20 ),
				],
			]
		);

		return new \WP_REST_Response( [ 'unassigned' => $unassigned, 'failed' => count( $ids ) - $unassigned ] );
	}

	private function media_delete_service(): MediaDeleteService {
		return $this->media_delete_service ??= new MediaDeleteService();
	}
}
