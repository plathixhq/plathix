<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderDTO;
use Plathix\Core\FolderRepository;

/**
 * Assembles the deferred sidebar bootstrap payload from partial datasets.
 *
 * Responsibility: combine system folders, root user folders, and ancestry path
 * for a deep open_id into the bootstrap arrays consumed by the sidebar runtime.
 *
 * This class does NOT decide whether to use deferred mode — that remains in Assets.
 * It receives pre-fetched FolderDTO lists and combines them into the final payload.
 */
final class SidebarBootstrapAssembler
{
	public function __construct(
		private readonly FolderCountService $folders,
		private readonly FolderRepository $repository,
	) {
	}

	/**
	 * @param  string   $taxonomy
	 * @param  int      $open_id
	 * @param  int[]   &$loaded_parents
	 * @return array<int, array<string, mixed>>
	 */

	public function build(string $taxonomy, int $open_id, array &$loaded_parents): array
	{
		// 1. Root system folders (All Files, Uncategorized, Trash).
		//    getAllCached always includes them — we extract only isProtected items
		//    rather than calling getChildren(0) which intentionally excludes them.
		$all_folders      = $this->folders->getAllCached( $taxonomy );
		$system_folders   = array_filter( $all_folders, static fn (FolderDTO $f): bool => $f->isProtected );
		$root_user_dtos   = $this->folders->getChildren( 0, $taxonomy );

		$folders = array_merge(
			array_map( static fn (FolderDTO $d): array => $d->toArray(), $system_folders ),
			array_map( static fn (FolderDTO $d): array => $d->toArray(), $root_user_dtos ),
		);

		if ( $open_id <= 0 ) {
			$loaded_parents = array_values( array_unique( $loaded_parents ) );
			return $folders;
		}

		// 2. Walk ancestry from open_id up to root, loading children at each level.
		$ancestor_ids = $this->repository->getAncestryIds( $open_id, $taxonomy );

		// Traverse from root downward: ancestors are [parent, grandparent, ...],
		// so reverse to go root → ... → direct parent → open_id.
		$path = array_reverse( $ancestor_ids );
		$path[] = $open_id;

		foreach ( $path as $node_id ) {
			$child_dtos = $this->folders->getChildren( $node_id, $taxonomy );
			if ( ! empty( $child_dtos ) || $node_id === $open_id ) {
				$folders          = array_merge( $folders, array_map( static fn (FolderDTO $d): array => $d->toArray(), $child_dtos ) );
				$loaded_parents[] = $node_id;
			}
		}

		// De-duplicate: same folder may appear in root_user_dtos and as an ancestor child.
		$seen    = [];
		$deduped = [];
		foreach ( $folders as $folder ) {
			$id = (int) ( $folder['id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$deduped[]   = $folder;
		}

		$loaded_parents = array_values( array_unique( $loaded_parents ) );

		return $deduped;
	}
}
