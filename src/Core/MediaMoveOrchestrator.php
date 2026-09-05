<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;

/**
 * Единственный источник истины для trash-aware маршрутизации перемещения файлов
 * ([internal], [internal]).
 *
 * Три независимых входа (REST `MediaController::move_items`, legacy AJAX
 * `AjaxRouter::move_items`, PublicApi/WP-CLI `AssignmentsApi::defaultItemsMover`)
 * вызывали `FolderAssignmentService::move_items_bulk()` напрямую, каждый со своей
 * реализацией (или без неё вовсе) 4-way маршрутизации `is_trash × is_into_trash_target`.
 * Это ровно тот баг-класс, что чинили [internal]/#235, но через другие входы —
 * `route()` собирает логику в одном месте, вызывающие места становятся тонкими адаптерами.
 */
final class MediaMoveOrchestrator
{
	/**
	 * @param int[] $ids
	 */
	public static function route(array $ids, int $folder_id, string $taxonomy): MediaMoveResult {
		// [internal]: drag-drop из Корзины/trashed-папки на обычную папку раньше только
		// переназначал term (move_items_bulk), не трогая post_status — файл оставался
		// в trash с новым term-relation, но UI показывал success. Trash-элементы batch'а
		// восстанавливаются через уже существующий bulk_restore() (restore + assign
		// атомарно по результату), не-trash элементы идут обычным move-путём.
		//
		// [internal]: симметричный, не закрытый ранее случай — вход В «Корзину». Не-trash
		// файл с target=«Корзина» раньше тоже шёл в $normal_ids (move_items_bulk), получая
		// только term-переназначение без перевода post_status в trash. Развели на явную
		// 4-way маршрутизацию: is_trash x is_target_trash_folder.
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
				// Файл уже в «Корзине» (post_status=trash), цель — снова «Корзина»: уже в
				// целевом состоянии, no-op ([internal] не про этот случай).
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
			$restore_result = ( new MediaDeleteService() )->bulk_restore( $trash_ids, $folder_id, $taxonomy );
			$restored        = $restore_result->restored;
			$moved          += count( $restored );
			$failed          = array_merge( $failed, $restore_result->failed );
			$skipped        += count( $restore_result->skipped );
		}

		if ( $into_trash_ids !== [] ) {
			$trash_result = ( new MediaDeleteService() )->bulk_trash( $into_trash_ids, $taxonomy );
			$trashed       = $trash_result->trashed;
			$moved        += count( $trashed );
			$failed        = array_merge( $failed, $trash_result->failed );
			$skipped      += count( $trash_result->skipped );
		}

		if ( $normal_ids !== [] ) {
			$repository   = new FolderRepository();
			$cache        = Cache::make();
			$count_service = new FolderCountService( $repository, $cache );
			$assignment   = new FolderAssignmentService( $repository, $count_service, $cache );

			$move_result        = $assignment->move_items_bulk( $normal_ids, $folder_id, $taxonomy );
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
