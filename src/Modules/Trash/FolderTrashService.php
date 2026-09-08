<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaTrashLock;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\Logger;

final class FolderTrashService
{
	public const META_TRASHED        = '_plathix_folder_trashed';
	public const META_TIME           = '_plathix_folder_trash_time';
	public const META_PARENT         = '_plathix_folder_trash_parent';
	public const META_POSITION       = '_plathix_folder_trash_position';
	public const META_ORIGINAL_NAME  = '_plathix_folder_trash_original_name';

	private FolderRepository $repository;
	private FolderTreeService $tree;
	private FolderCountService $countService;

	public function __construct(?FolderRepository $repository = null, ?FolderTreeService $tree = null, ?FolderCountService $countService = null)
	{
		$this->repository = $repository ?? new FolderRepository();

		// tree's own dependency and this class's new recursive-count decrement — avoids
		// constructing two separate instances (cheap, but pointless duplication) when
		// $tree is not explicitly injected.
		$this->countService = $countService ?? new FolderCountService( $this->repository, Cache::make() );

		$this->tree = $tree ?? new FolderTreeService( $this->repository, $this->countService );
	}

	/**
	 * @return bool
	 */

	public function trash(int $id, string $taxonomy, string $on_children = FolderTreeService::DEFAULT_ON_CHILDREN): bool
	{
		$lock = $this->tree->acquireStructureLock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return false;
		}

		try {
			return $this->trashBody( $id, $taxonomy, $on_children );
		} finally {
			$this->tree->releaseStructureLock( $taxonomy, $lock );
		}
	}

	private function trashBody(int $id, string $taxonomy, string $on_children): bool
	{
		if ( $id <= 0 || $this->repository->isUncategorizedFolder( $id, $taxonomy ) ) {
			return false;
		}

		if ( 'delete' === $on_children ) {

			foreach ( $this->repository->getChildrenIds( $id, $taxonomy ) as $child_id ) {
				if ( ! $this->trashBody( $child_id, $taxonomy, 'delete' ) ) {
					Logger::warning(
						'Folder trash: child node failed to trash, cascade continues',
						[ 'child_id' => $child_id, 'parent_id' => $id, 'taxonomy' => $taxonomy ]
					);
				}
			}
		} else {
			$term       = $this->repository->getById( $id, $taxonomy );
			$new_parent = $term instanceof \WP_Term ? (int) $term->parent : 0;
			$reparented = $this->repository->bulkUpdateParent( $id, $new_parent, $taxonomy );
			if ( $reparented instanceof \WP_Error ) {

				Logger::warning(
					'Folder trash: reattach-on-trash failed',
					[
						'folder_id' => $id,
						'taxonomy'  => $taxonomy,
						'code'      => (string) $reparented->get_error_code(),
						'message'   => (string) $reparented->get_error_message(),
					]
				);
				return false;
			}
		}

		return $this->markTrashed( $id, $taxonomy );
	}

	private function markTrashed(int $id, string $taxonomy): bool
	{

		if ( (string) $this->repository->getMeta( $id, self::META_TRASHED ) === '1' ) {
			return true;
		}

		$term = $this->repository->getById( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			Logger::warning(
				'Folder trash: term not found while marking as trashed',
				[ 'folder_id' => $id, 'taxonomy' => $taxonomy ]
			);
			return false;
		}

		$parent   = (int) $term->parent;
		$position = (int) $this->repository->getMeta( $id, PLATHIX_TERM_POSITION );

		$this->repository->setMeta( $id, self::META_PARENT, $parent );
		$this->repository->setMeta( $id, self::META_POSITION, $position );
		$this->repository->setMeta( $id, self::META_TIME, time() );
		$this->repository->setMeta( $id, self::META_TRASHED, '1' );

		$this->maybeTrashFiles( $id, $taxonomy );

		return true;
	}

	private function maybeTrashFiles(int $id, string $taxonomy): void
	{
		$object_ids = get_objects_in_term( $id, $taxonomy );
		if ( is_wp_error( $object_ids ) ) {
			return;
		}

		if ( get_option( TrashSettings::OPTION_DELETE_FILES, '' ) === '1' ) {

			foreach ( (array) $object_ids as $object_id ) {
				$object_id = (int) $object_id;
				$lock      = ( new MediaTrashLock() )->acquire( $object_id );
				if ( is_wp_error( $lock ) ) {
					continue;
				}

				try {
					$post = get_post( $object_id );
					if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' ) {
						continue;
					}

					wp_trash_post( $object_id );
				} finally {
					( new MediaTrashLock() )->release( $object_id, $lock['token'] ?? '' );
				}
			}
			return;
		}

		$object_ids = (array) $object_ids;
		if ( $object_ids === [] ) {
			return;
		}

		foreach ( $object_ids as $object_id ) {
			wp_set_object_terms( (int) $object_id, [], $taxonomy, false );
		}

		Cache::onAttachmentChange( null, $taxonomy );
	}
}
