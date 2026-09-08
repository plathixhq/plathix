<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\HiddenFolders;
use Plathix\Modules\Trash\FolderTrashService;

final class FolderTrashController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderTreeService $tree,
	) {
	}

	public function trashedFolders(\WP_REST_Request $request): \WP_REST_Response {
		$taxonomy = $this->requestTaxonomy( $request );
		$ids      = HiddenFolders::ids( $taxonomy );

		$saved_parent = [];
		foreach ( $ids as $id ) {
			$saved_parent[ (int) $id ] = (int) $this->repository->getMeta( (int) $id, FolderTrashService::META_PARENT );
		}
		$kids_count = array_count_values( array_values( $saved_parent ) );

		$folders = [];
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$term = $this->repository->getById( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$folders[] = [
				'id'        => $id,
				'name'      => (string) $term->name,
				'parent'    => $saved_parent[ $id ],
				'color'     => (string) $this->repository->getMeta( $id, PLATHIX_TERM_COLOR ),
				'kids'      => (int) ( $kids_count[ $id ] ?? 0 ),
				'deletedAt' => (int) $this->repository->getMeta( $id, FolderTrashService::META_TIME ),
			];
		}

		return new \WP_REST_Response( [ 'success' => true, 'folders' => $folders ], 200 );
	}

	public function restoreFolder(\WP_REST_Request $request): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->requestTaxonomy( $request );

		$result = ( new \Plathix\PublicApi\FoldersApi() )->restoreFolder( $id, $taxonomy );

		if ( ! $result['restored'] ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Folder is not in Trash or no longer exists.', 'plathix' ) ],
				410
			);
		}

		return new \WP_REST_Response(
			[
				'success'      => true,
				'fallbackRoot' => $result['fallbackRoot'],
				'parent'       => $result['parent'],
			],
			200
		);
	}

	public function purgeFolder(\WP_REST_Request $request): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->requestTaxonomy( $request );

		$lock = $this->tree->acquireStructureLock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Trash is busy, try again.', 'plathix' ) ],
				503
			);
		}

		try {
			if ( (string) $this->repository->getMeta( $id, FolderTrashService::META_TRASHED ) !== '1' ) {
				return new \WP_REST_Response(
					[ 'success' => false, 'message' => __( 'Folder is not in Trash.', 'plathix' ) ],
					409
				);
			}

			$ok = $this->tree->deleteRecursiveUnderLock( $id, $taxonomy, 'delete' );
		} finally {
			$this->tree->releaseStructureLock( $taxonomy, $lock );
		}

		return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 500 );
	}
}
