<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaTrashLock;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\Logger;

final class FolderRestoreService
{
	private FolderRepository $repository;
	private FolderCountService $countService;
	private FolderTreeService $tree;

	public function __construct(?FolderRepository $repository = null, ?FolderCountService $countService = null, ?FolderTreeService $tree = null)
	{
		$this->repository    = $repository ?? new FolderRepository();
		$this->countService = $countService ?? new FolderCountService( $this->repository, Cache::make() );

		$this->tree = $tree ?? new FolderTreeService( $this->repository, $this->countService );
	}

	/**
	 * @return array{restored:bool, fallbackRoot:bool, parent:int}
	 */

	public function restore(int $id, string $taxonomy): array
	{
		$result = [ 'restored' => false, 'fallbackRoot' => false, 'parent' => 0 ];

		if ( $id <= 0 ) {
			return $result;
		}

		$lock = $this->tree->acquireStructureLock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return $result;
		}

		try {
			return $this->restoreLocked( $id, $taxonomy, $result );
		} finally {
			$this->tree->releaseStructureLock( $taxonomy, $lock );
		}
	}

	/**
	 * @param array{restored:bool, fallbackRoot:bool, parent:int} $result
	 * @return array{restored:bool, fallbackRoot:bool, parent:int}
	 */
	private function restoreLocked(int $id, string $taxonomy, array $result): array
	{

		if ( (string) $this->repository->getMeta( $id, FolderTrashService::META_TRASHED ) !== '1' ) {
			return $result;
		}

		$saved_parent   = (int) $this->repository->getMeta( $id, FolderTrashService::META_PARENT );
		$saved_position = (int) $this->repository->getMeta( $id, FolderTrashService::META_POSITION );

		$target_parent = $this->resolveParent( $saved_parent, $taxonomy );
		$fallback_root = ( $saved_parent > 0 && $target_parent === 0 );

		$this->repository->updateParent( $id, $target_parent, $taxonomy );
		$this->repository->setMeta( $id, PLATHIX_TERM_POSITION, $saved_position );

		$this->repository->deleteMeta( $id, FolderTrashService::META_TRASHED );
		$this->repository->deleteMeta( $id, FolderTrashService::META_TIME );
		$this->repository->deleteMeta( $id, FolderTrashService::META_PARENT );
		$this->repository->deleteMeta( $id, FolderTrashService::META_POSITION );

		$this->restoreOriginalName( $id, $target_parent, $taxonomy );

		//

		// FolderCascadeCountLifecycleTest::testRestoreEventsRippleThroughTheNewParentChain

		$this->restoreFolderFiles( $id, $taxonomy );

		$this->countService->invalidate( $taxonomy );

		return [ 'restored' => true, 'fallbackRoot' => $fallback_root, 'parent' => $target_parent ];
	}

	private function restoreFolderFiles(int $folder_id, string $taxonomy): void
	{
		if ( get_option( TrashSettings::OPTION_DELETE_FILES, '' ) !== '1' ) {
			return;
		}

		$object_ids = get_objects_in_term( $folder_id, $taxonomy );
		if ( is_wp_error( $object_ids ) ) {
			return;
		}

		$untrashed = 0;
		foreach ( (array) $object_ids as $object_id ) {
			$object_id = (int) $object_id;

			$lock = ( new MediaTrashLock() )->acquire( $object_id );
			if ( is_wp_error( $lock ) ) {
				continue;
			}

			try {
				$post = get_post( $object_id );

				if ( $post instanceof \WP_Post && $post->post_status === 'trash' ) {
					wp_untrash_post( $object_id );
					++$untrashed;
				}
			} finally {
				( new MediaTrashLock() )->release( $object_id, $lock['token'] ?? '' );
			}
		}

		if ( $untrashed > 0 ) {
			Cache::onAttachmentChange( null, $taxonomy );
		}
	}

	private function restoreOriginalName(int $id, int $parent, string $taxonomy): void
	{
		$original_name = (string) $this->repository->getMeta( $id, FolderTrashService::META_ORIGINAL_NAME );
		if ( $original_name === '' ) {
			return;
		}

		$result = $this->repository->update( $id, [ 'name' => $original_name ], $taxonomy );

		if ( is_wp_error( $result ) ) {
			$unique_name = $this->generateUniqueName( $original_name, $parent, $taxonomy );
			if ( $unique_name !== null ) {
				$result = $this->repository->update( $id, [ 'name' => $unique_name ], $taxonomy );
			}

			if ( $unique_name === null || is_wp_error( $result ) ) {
				Logger::warning(
					'Folder restore: could not restore original name, kept technical placeholder',
					[ 'folder_id' => $id, 'original_name' => $original_name, 'taxonomy' => $taxonomy ]
				);
			}
		}

		$this->repository->deleteMeta( $id, FolderTrashService::META_ORIGINAL_NAME );
	}

	private function generateUniqueName(string $base_name, int $parent, string $taxonomy): ?string
	{
		for ( $suffix = 2; $suffix <= 50; $suffix++ ) {
			$candidate = $base_name . ' (' . $suffix . ')';
			if ( ! term_exists( $candidate, $taxonomy, $parent ) ) {
				return $candidate;
			}
		}

		return null;
	}

	private function resolveParent(int $saved_parent, string $taxonomy): int
	{
		if ( $saved_parent <= 0 ) {
			return 0;
		}

		$parent_term = $this->repository->getById( $saved_parent, $taxonomy );
		if ( ! $parent_term instanceof \WP_Term ) {
			return 0;

		}

		if ( (string) $this->repository->getMeta( $saved_parent, FolderTrashService::META_TRASHED ) === '1' ) {
			return 0;

		}

		return $saved_parent;
	}
}
