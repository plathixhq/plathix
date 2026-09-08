<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;

final class MediaMoveOrchestrator
{
	/**
	 * @param int[] $ids
	 */
	public static function route(array $ids, int $folder_id, string $taxonomy): MediaMoveResult {

		//

		$trash_folder_id = TrashFolder::id( $taxonomy );
		$trash_ids       = [];
		$normal_ids      = [];
		$into_trash_ids  = [];
		$already_trashed_skipped = 0;
		foreach ( $ids as $id ) {
			$post      = get_post( $id );
			$is_trash  = $post instanceof \WP_Post && $post->post_status === 'trash';
			$is_into_trash_target = $trash_folder_id > 0 && $folder_id === $trash_folder_id;

			if ( $is_trash && $is_into_trash_target ) {

				++$already_trashed_skipped;
			} elseif ( $is_trash ) {
				$trash_ids[] = $id;
			} elseif ( $is_into_trash_target ) {
				$into_trash_ids[] = $id;
			} else {
				$normal_ids[] = $id;
			}
		}

		$restored = [];
		$trashed  = [];
		$moved    = 0;
		$skipped  = $already_trashed_skipped;
		$failed   = [];
		$counts_recomputed = [];
		$counts   = [];

		if ( $trash_ids !== [] ) {
			$restore_result = ( new MediaDeleteService() )->bulkRestore( $trash_ids, $folder_id, $taxonomy );
			$restored        = $restore_result->restored;
			$moved          += count( $restored );
			$failed          = array_merge( $failed, $restore_result->failed );
			$skipped        += count( $restore_result->skipped );
		}

		if ( $into_trash_ids !== [] ) {
			$trash_result = ( new MediaDeleteService() )->bulkTrash( $into_trash_ids, $taxonomy );
			$trashed       = $trash_result->trashed;
			$moved        += count( $trashed );
			$failed        = array_merge( $failed, $trash_result->failed );
			$skipped      += count( $trash_result->skipped );
		}

		if ( $normal_ids !== [] ) {
			$repository   = new FolderRepository();
			$cache        = Cache::make();
			$countService = new FolderCountService( $repository, $cache );
			$assignment   = new FolderAssignmentService( $repository, $countService, $cache );

			$move_result        = $assignment->moveItemsBulk( $normal_ids, $folder_id, $taxonomy );
			$moved             += $move_result['moved'];
			$skipped           += $move_result['skipped'];
			$failed             = array_merge( $failed, $move_result['failed'] );
			$counts_recomputed  = $move_result['counts_recomputed'];
			$counts             = $move_result['counts'];
		}

		return new MediaMoveResult(
			moved: $moved,
			skipped: $skipped,
			failed: $failed,
			folder_id: $folder_id,
			taxonomy: $taxonomy,
			counts_recomputed: $counts_recomputed,
			counts: $counts,
			restored: $restored,
			trashed: $trashed
		);
	}
}
