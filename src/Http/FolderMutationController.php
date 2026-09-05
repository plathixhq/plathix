<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderMutationService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\RateLimiter;

/**
 * REST-мутации одной папки: create / update / delete. Часть распила FolderController
 * ([internal] #94) — single-mutation слой.
 *
 * update применяет изменения через общий Core\FolderMutationService::apply_changes (то же
 * ядро, что batch_update_folders — устранён дубль прежнего apply_batch_folder_updates), НО
 * сохраняет свою семантику: rename XOR (move/position/color) — при непустом name применяется
 * ТОЛЬКО переименование (ранний возврат, как в исходном коде), иначе move+position+color.
 * Аудит-эмиссию (folder_renamed/folder_moved) и коды ошибок (rename→422, move/order→409),
 * term-410-guard и parent-skip делает контроллер — сервис аудит/статусы не знает.
 *
 * Маршрутизация и permission_callback остаются в RestController/RestRouteRegistry.
 */
final class FolderMutationController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderTreeService $tree,
		private readonly RateLimiter $rate_limiter,
		private readonly FolderMutationService $mutations,
	) {
	}

	public function create_folder(\WP_REST_Request $request): \WP_REST_Response {
		if ( ! $this->rate_limiter->attempt_action( 'create_folder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are creating folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$taxonomy = $this->request_taxonomy( $request );
		$id       = $this->tree->create( (string) $request->get_param( 'name' ), (int) $request->get_param( 'parent_id' ), $taxonomy );

		if ( is_wp_error( $id ) ) {
			/** @var \WP_Error $id Narrowed inside is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */
			return $this->error_response( $id, 422 );
		}
		/** @var int $id Narrowed after is_wp_error() guard (see [internal] #6). */

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

	public function update_folder(\WP_REST_Request $request): \WP_REST_Response {
		// [internal]: throttle изменения папок (XOR rename/move/color; move перестраивает
		// дерево — не O(1)). Один guard на входе покрывает все ветки. fixed-окно (реестр).
		if ( ! $this->rate_limiter->attempt_action( 'update_folder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are changing folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->request_taxonomy( $request );
		$term     = $this->repository->get_by_id( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		// Rename-ветка (XOR): при непустом name применяется ТОЛЬКО переименование и запрос
		// завершается — move/position/color игнорируются, как в исходном FolderController.
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( $id > 0 && $name !== '' ) {
			$error = $this->mutations->apply_changes( $id, [ 'name' => $name ], $taxonomy );
			if ( $error instanceof \WP_Error ) {
				return $this->error_response( $error, 422 );
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

			return new \WP_REST_Response( [ 'success' => true ] );
		}

		// Move / position / color-ветка. parent_id кладём в changes только при реальной смене
		// (parent-skip — как в исходном коде: нужен term->parent). Коды move/order-ошибок → 409.
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

		$error = $this->mutations->apply_changes( $id, $changes, $taxonomy );
		if ( $error instanceof \WP_Error ) {
			return $this->error_response( $error, 409 );
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

	public function delete_folder(\WP_REST_Request $request): \WP_REST_Response {
		// [internal]: throttle рекурсивного удаления (delete_recursive обходит поддерево,
		// пишет меты + опц. wp_trash_post на файл — дорогой per-request). fixed-окно (реестр).
		if ( ! $this->rate_limiter->attempt_action( 'delete_folder', get_current_user_id() ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rate_limit',
					'message' => __( 'You are deleting folders too quickly. Please wait about a minute and try again.', 'plathix' ),
				],
				429
			);
		}

		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->request_taxonomy( $request );
		$term     = $this->repository->get_by_id( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		$on_children = in_array( (string) $request->get_param( 'on_children' ), [ 'reattach', 'delete' ], true )
			? (string) $request->get_param( 'on_children' )
			: 'reattach';

		$ok = $this->tree->delete_recursive( $id, $taxonomy, $on_children );

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
