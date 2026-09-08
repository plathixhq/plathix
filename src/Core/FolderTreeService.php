<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Logger;
use Plathix\Modules\Trash\FolderTrashService;

final class FolderTreeService
{

	public const DEFAULT_ON_CHILDREN = 'delete';

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $countService
	) {
	}

	public function create(string $name, int $parent, string $taxonomy): int|\WP_Error {
		$detailed = $this->createDetailed( $name, $parent, $taxonomy );

		return is_wp_error( $detailed ) ? $detailed : $detailed['id'];
	}

	/**
	 * @return array{id: int, created: bool}|\WP_Error
	 */

	public function createDetailed(string $name, int $parent, string $taxonomy): array|\WP_Error {
		$normalized = $this->normalizeName( $name );
		if ( $error = $this->validateName( $normalized ) ) {
			return $error;
		}

		if ( $error = $this->validateTaxonomy( $taxonomy ) ) {
			return $error;
		}

		if ( $this->repository->isUncategorizedFolder( $parent, $taxonomy ) ) {
			return new \WP_Error( 'invalid_parent', __( 'Cannot create subfolders inside Uncategorized.', 'plathix' ) );
		}

		$depth_limit = (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH );
		if ( $depth_limit > 0 && $this->getDepth( $parent, $taxonomy ) >= $depth_limit ) {
			return new \WP_Error( 'depth_limit', __( 'Maximum folder nesting depth reached.', 'plathix' ) );
		}

		$lock_name    = $this->structureLockName( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		try {
			return $this->createLocked( $normalized, $parent, $taxonomy );
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}
	}

	/** @return array{id: int, created: bool}|\WP_Error */
	private function createLocked(string $normalized, int $parent, string $taxonomy): array|\WP_Error {

		if ( $parent > 0 && $this->hasTrashedAncestor( $parent, $taxonomy ) ) {
			return new \WP_Error( 'parent_trashed', __( 'Cannot create a folder inside a folder that is in Trash.', 'plathix' ), [ 'status' => 409 ] );
		}

		$exists = term_exists( $normalized, $taxonomy, $parent );
		if ( $exists ) {
			$existing_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
			if ( $existing_id > 0 ) {

				if ( ! in_array( $existing_id, HiddenFolders::ids( $taxonomy ), true ) ) {
					return [ 'id' => $existing_id, 'created' => false ];
				}

				if ( ! $this->repository->getMeta( $existing_id, FolderTrashService::META_ORIGINAL_NAME ) ) {
					$existing_term = $this->repository->getById( $existing_id, $taxonomy );
					if ( $existing_term instanceof \WP_Term ) {
						$this->repository->setMeta( $existing_id, FolderTrashService::META_ORIGINAL_NAME, $existing_term->name );
					}
				}
				// Soft-trashed term blocks wp_insert_term by both name and slug; free both so a fresh
				// term can be created. Name stays hidden (trashed), slug uniqueness is guaranteed by id.
				$upd = wp_update_term( $existing_id, $taxonomy, [
					'name' => (string) $existing_id . '__trashed',
					'slug' => (string) $existing_id . '__trashed',
				] );
				if ( is_wp_error( $upd ) ) {
					Logger::error( __METHOD__ . ': slug-rename failed for term.', [
						'term_id' => $existing_id,
						'error'   => $upd->get_error_message(),
					] );
				}
			}
		}

		$inserted = $this->repository->insert( sanitize_text_field( $normalized ), $parent, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		/**
		 * @var int $inserted
		 */

		$this->repository->setMeta( (int) $inserted, PLATHIX_TERM_POSITION, $this->nextPosition( (int) $inserted, $parent, $taxonomy ) );
		$this->countService->invalidate( $taxonomy );
		do_action( 'plathix/folder/created', (int) $inserted, $taxonomy );

		return [ 'id' => (int) $inserted, 'created' => true ];
	}

	public function rename(int $id, string $name, string $taxonomy): bool|\WP_Error {
		if ( $this->repository->isUncategorizedFolder( $id, $taxonomy ) ) {
			return new \WP_Error( 'protected_folder', __( 'Uncategorized cannot be renamed.', 'plathix' ) );
		}

		$normalized = $this->normalizeName( $name );
		if ( $error = $this->validateName( $normalized ) ) {
			return $error;
		}

		$result = $this->repository->update( $id, [ 'name' => sanitize_text_field( $normalized ) ], $taxonomy );
		if ( ! is_wp_error( $result ) ) {
			$this->countService->invalidate( $taxonomy );
			do_action( 'plathix/folder/updated', $id, $taxonomy );

			return $result;
		}

		if ( in_array( $result->get_error_code(), [ 'invalid_term', 'invalid_taxonomy' ], true ) ) {
			return new \WP_Error(
				'folder_gone',
				__( 'This folder was removed. Please refresh the folder tree and try again.', 'plathix' ),
				[ 'status' => 409 ]
			);
		}

		return $result;
	}

	public function move(int $id, int $parent, string $taxonomy): bool|\WP_Error {
		if ( $error = $this->validateSelfMove( $id, $parent ) ) {
			return $error;
		}

		if ( $this->isDescendantOf( $parent, $id, $taxonomy ) ) {
			return new \WP_Error( 'cycle_detected', __( 'Cannot move a folder into one of its own subfolders.', 'plathix' ), [ 'status' => 409 ] );
		}

		$depth_limit = (int) apply_filters( 'plathix/folder/depth_limit', PLATHIX_MAX_DEPTH );
		if ( $depth_limit > 0 && ( $this->getDepth( $parent, $taxonomy ) + $this->getSubtreeHeight( $id, $taxonomy ) ) >= $depth_limit ) {
			return new \WP_Error( 'depth_limit', __( 'Maximum folder nesting depth reached.', 'plathix' ), [ 'status' => 409 ] );
		}

		$lock_name    = $this->structureLockName( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		// to decrement its recursive chain below. term is guaranteed to exist here (guards
		// above already validated $id/$parent); a null read would mean a race with a

		$old_term   = $this->repository->getById( $id, $taxonomy );
		$old_parent = $old_term instanceof \WP_Term ? (int) $old_term->parent : 0;

		try {

			if ( $parent > 0 && $this->hasTrashedAncestor( $parent, $taxonomy ) ) {
				return new \WP_Error( 'parent_trashed', __( 'Cannot move a folder into a folder that is in Trash.', 'plathix' ), [ 'status' => 409 ] );
			}

			$result = $this->repository->update( $id, [ 'parent' => $parent ], $taxonomy );
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}

		if ( ! is_wp_error( $result ) ) {

			// subtree) leaves the old parent's chain and enters the new one — same
			// direct-own-count decrement/increment pattern as FolderTrashService/
			// FolderRestoreService, but using getRecursiveCount() here (not getCount()):
			// unlike a single-node trash/restore, a MOVED folder can carry its whole
			// subtree with it, so the full recursive total must transfer, not just its
			// own direct files.
			$moved_count = $this->countService->getRecursiveCount( $id, $taxonomy );
			if ( $moved_count > 0 ) {
				if ( $old_parent > 0 ) {
					$this->countService->incrementRecursiveChain( $old_parent, $taxonomy, -$moved_count );
				}
				if ( $parent > 0 ) {
					$this->countService->incrementRecursiveChain( $parent, $taxonomy, $moved_count );
				}
			}

			$this->countService->invalidate( $taxonomy );
			do_action( 'plathix/folder/updated', $id, $taxonomy );

			return $result;
		}

		if ( in_array( $result->get_error_code(), [ 'invalid_term', 'invalid_taxonomy' ], true ) ) {
			return new \WP_Error(
				'folder_gone',
				__( 'This folder was removed. Please refresh the folder tree and try again.', 'plathix' ),
				[ 'status' => 409 ]
			);
		}

		return $result;
	}

	private function structureLockName(string $taxonomy): string {
		return 'plathix_mv_' . get_current_blog_id() . '_' . md5( $taxonomy );
	}

	/**
	 * @return array{mode: string, opt_key: string|null}
	 */

	public function acquireStructureLock(string $taxonomy): array {
		return ( new \Plathix\Infrastructure\JobLockService() )->acquireOrder( $this->structureLockName( $taxonomy ) );
	}

	/**
	 * @param array{mode: string, opt_key: string|null} $lock_result
	 */

	public function releaseStructureLock(string $taxonomy, array $lock_result): void {
		( new \Plathix\Infrastructure\JobLockService() )->releaseOrder( $this->structureLockName( $taxonomy ), $lock_result );
	}

	public function getDepth(int $folder_id, string $taxonomy): int {

		$depth = 0;
		$current = $folder_id;
		$visited = [];

		while ( $current > 0 ) {
			if ( isset( $visited[ $current ] ) ) {
				break;
			}
			$visited[ $current ] = true;

			$term = $this->repository->getById( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
			++$depth;
		}

		return $depth;
	}

	/**
	 * @param array<int, bool> $visited
	 */

	private function getSubtreeHeight(int $folder_id, string $taxonomy, array $visited = []): int {
		if ( isset( $visited[ $folder_id ] ) ) {
			return 0;
		}
		$visited[ $folder_id ] = true;

		$children = $this->repository->getChildrenIds( $folder_id, $taxonomy );
		if ( $children === [] ) {
			return 0;
		}

		$max_child_height = 0;
		foreach ( $children as $child_id ) {
			$max_child_height = max( $max_child_height, $this->getSubtreeHeight( $child_id, $taxonomy, $visited ) );
		}

		return 1 + $max_child_height;
	}

	/**
	 * @param string $on_children
	 */

	public function deleteRecursive(int $id, string $taxonomy, string $on_children = self::DEFAULT_ON_CHILDREN): bool {
		$runner = apply_filters( 'plathix/folder/trash_runner', FolderTrashRunner::trash(...) );

		$trashed = (bool) $runner( $id, $taxonomy, $on_children );
		if ( $trashed ) {
			$this->countService->invalidate( $taxonomy );
			do_action( 'plathix/folder/trashed', $id, $taxonomy );
		}

		return $trashed;
	}

	public function deleteRecursivePermanent(int $id, string $taxonomy, string $on_children = self::DEFAULT_ON_CHILDREN): bool {
		$lock_name    = $this->structureLockName( $taxonomy );
		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock         = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return false;
		}

		try {
			return $this->deleteRecursiveBody( $id, $taxonomy, $on_children );
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}
	}

	public function deleteRecursiveUnderLock(int $id, string $taxonomy, string $on_children = self::DEFAULT_ON_CHILDREN): bool {
		return $this->deleteRecursiveBody( $id, $taxonomy, $on_children );
	}

	private function deleteRecursiveBody(int $id, string $taxonomy, string $on_children): bool {
		if ( $id <= 0 || $this->repository->isUncategorizedFolder( $id, $taxonomy ) ) {
			return false;
		}

		// UNLESS the folder was already soft-trashed (FolderTrashService::markTrashed()
		// already decremented it when it entered Trash — the normal retention-cleanup
		// path calls THIS method on an already-trashed folder). Decrementing again here
		// unconditionally would double-subtract the same files. A live (never-trashed)
		// folder reaching this method directly (DataWiper, or a future direct-delete
		// caller) has NOT been decremented yet, so it must be here. Read parent + trashed
		// status + own count BEFORE repository->delete() removes the term — wp_delete_term()
		// clears term_relationships as part of the delete, so getCount() after delete()
		// would read back 0 regardless of what was actually there.
		$term_before_delete    = $this->repository->getById( $id, $taxonomy );
		$parent_before_delete  = $term_before_delete instanceof \WP_Term ? (int) $term_before_delete->parent : 0;
		$was_already_trashed   = (string) $this->repository->getMeta( $id, FolderTrashService::META_TRASHED ) === '1';

		$own_count_before_delete = $was_already_trashed ? 0 : $this->countService->getCount( $id, $taxonomy );

		$children = $this->repository->getChildrenIds( $id, $taxonomy );

		if ( $on_children === 'delete' ) {
			foreach ( $children as $child_id ) {
				$this->deleteRecursiveBody( $child_id, $taxonomy, 'delete' );
			}
		} else {
			$term = $this->repository->getById( $id, $taxonomy );
			$new_parent = $term instanceof \WP_Term ? (int) $term->parent : 0;
			$reparented = $this->reparentChildren( $id, $new_parent, $taxonomy );
			if ( $reparented instanceof \WP_Error ) {
				return false;
			}
		}

		$deleted = FolderCountLifecycle::suppress(
			fn (): bool => $this->repository->delete( $id, $taxonomy )
		);
		if ( $deleted ) {
			if ( null !== $own_count_before_delete && $own_count_before_delete > 0 && $parent_before_delete > 0 ) {
				$this->countService->incrementRecursiveChain( $parent_before_delete, $taxonomy, -$own_count_before_delete );
			}

			$this->countService->invalidate( $taxonomy );
			do_action( 'plathix/folder/deleted', $id, $taxonomy );
		}

		return $deleted;
	}

	public function reparentChildren(int $old_parent, int $new_parent, string $taxonomy): ?\WP_Error {
		if ( $old_parent === $new_parent ) {
			return null;
		}

		$result = $this->repository->bulkUpdateParent( $old_parent, $new_parent, $taxonomy );
		if ( is_wp_error( $result ) ) {
			/**
			 * @var \WP_Error $result
			 */

			return $result;
		}

		clean_term_cache( $this->repository->getChildrenIds( $new_parent, $taxonomy ), $taxonomy );
		delete_option( "{$taxonomy}_children" );
		$this->countService->invalidate( $taxonomy );

		return null;
	}

	public function setOrder(int $id, int $position, string $taxonomy): ?\WP_Error {

		$lock = $this->acquireStructureLock( $taxonomy );

		if ( 'none' === $lock['mode'] ) {
			return new \WP_Error( 'structure_locked', __( 'Folder structure is temporarily locked. Please try again in a moment.', 'plathix' ), [ 'status' => 409 ] );
		}

		try {
			$this->repository->setMeta( $id, PLATHIX_TERM_POSITION, $position );
		} finally {
			$this->releaseStructureLock( $taxonomy, $lock );
		}

		$this->countService->invalidate( $taxonomy );

		return null;
	}



	public function normalizeOrder(string $taxonomy, int $parent_id = 0): void {

		$lock_service = new \Plathix\Infrastructure\JobLockService();
		$lock_name    = $lock_service->orderLockName( $taxonomy, $parent_id );
		$lock         = $lock_service->acquireOrder( $lock_name );

		if ( 'none' === $lock['mode'] ) {
			return;
		}

		try {
			$children = $this->repository->getChildrenIds( $parent_id, $taxonomy );
			$position = 1000;
			foreach ( array_chunk( $children, 100 ) as $chunk ) {
				foreach ( $chunk as $child_id ) {
					$this->repository->setMeta( $child_id, PLATHIX_TERM_POSITION, $position );
					$position += 1000;
				}
			}
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
			$this->countService->invalidate( $taxonomy );
		}
	}

	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------

	private function normalizeName(string $name): string {
		return FolderName::normalize( $name );
	}

	private function validateName(string $normalized): ?\WP_Error {
		$errors = FolderName::validate( $normalized );

		if ( in_array( FolderName::ERROR_EMPTY, $errors, true ) ) {
			return new \WP_Error( 'empty_name', __( 'Folder name cannot be empty.', 'plathix' ) );
		}

		if ( in_array( FolderName::ERROR_LINE_BREAK, $errors, true ) || in_array( FolderName::ERROR_DANGEROUS_CHARS, $errors, true ) ) {
			return new \WP_Error( 'invalid_name_chars', __( 'Folder name contains control or bidirectional characters.', 'plathix' ) );
		}

		if ( in_array( FolderName::ERROR_TOO_LONG_BYTES, $errors, true ) ) {
			return new \WP_Error( 'name_too_long', __( 'Folder name is too long.', 'plathix' ) );
		}

		return null;
	}

	private function validateTaxonomy(string $taxonomy): ?\WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'plathix' ) );
		}

		return null;
	}

	private function validateSelfMove(int $id, int $parent): ?\WP_Error {
		if ( $id <= 0 || $id === $parent ) {
			return new \WP_Error( 'invalid_parent', __( 'A folder cannot be moved to itself.', 'plathix' ), [ 'status' => 409 ] );
		}

		return null;
	}

	private function isDescendantOf(int $candidate_parent, int $folder_id, string $taxonomy): bool {

		$visited = [];
		$current = $candidate_parent;
		while ( $current > 0 ) {
			if ( isset( $visited[ $current ] ) ) {
				break;
			}
			$visited[ $current ] = true;

			if ( $current === $folder_id ) {
				return true;
			}

			$term = $this->repository->getById( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
		}

		return false;
	}

	private function hasTrashedAncestor(int $parent, string $taxonomy): bool {

		//

		$hidden_ids = HiddenFolders::ids( $taxonomy );

		$visited = [];
		$current = $parent;
		while ( $current > 0 ) {
			if ( isset( $visited[ $current ] ) ) {
				break;
			}
			$visited[ $current ] = true;

			if ( in_array( $current, $hidden_ids, true ) ) {
				return true;
			}

			$term = $this->repository->getById( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}

			$current = (int) $term->parent;
		}

		return false;
	}

	private function nextPosition(int $current_id, int $parent, string $taxonomy): int {
		$max_position = 0;
		$sibling_ids  = $this->repository->getChildrenIds( $parent, $taxonomy );

		// Batch-prime term meta cache; getChildrenIds uses fields=ids which skips priming.
		if ( ! empty( $sibling_ids ) ) {
			update_termmeta_cache( $sibling_ids );
		}

		foreach ( $sibling_ids as $sibling_id ) {
			$sibling_id = (int) $sibling_id;
			if ( $sibling_id <= 0 || $sibling_id === $current_id ) {
				continue;
			}

			$position = (int) $this->repository->getMeta( $sibling_id, PLATHIX_TERM_POSITION );
			if ( $position > $max_position ) {
				$max_position = $position;
			}
		}

		return $max_position > 0 ? $max_position + 1000 : 1000;
	}
}
