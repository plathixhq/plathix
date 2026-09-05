<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;

/**
 * Инвариант возврата `counts` (map term_id => count затронутых папок, [internal]):
 * метод этого класса возвращает `counts`/`counts_recomputed` только если у него есть
 * реальный REST/AJAX JSON-потребитель, делающий точечное обновление UI-счётчиков вместо
 * полного refreshFolders()-round-trip.
 *
 * move_items_bulk() — да: REST-путь (MediaController::move_items()/set_items()-алиас,
 * оба через MediaMoveOrchestrator::route()) отдаёт WP_REST_Response с `counts`
 * JS-клиенту напрямую.
 * set_items() — нет: единственный прямой вызывающий, PRO FolderMetaBox::save(), это
 * save_post-хук без AJAX/REST-ответа — потреблять `counts` там некому.
 * unassign_items() — нет по той же причине: его REST-путь адаптирует результат к
 * {unassigned, failed} без counts до возврата клиенту.
 *
 * Расхождение между set_items() и move_items_bulk() уже возникало один раз без
 * зафиксированного правила ([internal]) — прежде чем добавлять новый метод с похожей
 * семантикой (assign/move/unassign) или менять форму ответа существующего, сверяйся с
 * этим инвариантом здесь, а не решай локально по месту.
 */
final class FolderAssignmentService
{
	private const CHUNK_SIZE = 500;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $count_service,
		private readonly Cache $cache
	) {
	}

	/**
	 * @param array<int|string> $item_ids
	 * @return array{assigned: int, skipped: int, failed: array<int>, folder_id: int, taxonomy: string, counts_recomputed: array<int>}
	 */
	public function set_items(array $item_ids, int $folder_id, string $taxonomy): array {
		$assigned = 0;
		$skipped = 0;
		$failed = [];
		$affected_folder_ids = [ $folder_id ];
		$use_folder = $folder_id > 0 && ! $this->repository->is_uncategorized_folder($folder_id, $taxonomy);

		foreach ( $item_ids as $item_id ) {
			$item_id = (int) $item_id;
			if ( $item_id <= 0 ) {
				$failed[] = $item_id;
				continue;
			}

			if ( ! $this->authorize_item($item_id, $taxonomy) ) {
				$failed[] = $item_id;
				continue;
			}

			$current = wp_get_object_terms($item_id, $taxonomy, [ 'fields' => 'ids' ]);
			if ( is_wp_error($current) ) {
				$failed[] = $item_id;
				continue;
			}
			$current = array_map('intval', (array) $current);

			if ( $current === [ $folder_id ] ) {
				++$skipped;
				continue;
			}

			foreach ( $current as $tid ) {
				$affected_folder_ids[] = $tid;
			}

			$result = $use_folder
				? wp_set_object_terms($item_id, [ $folder_id ], $taxonomy)
				: wp_set_object_terms($item_id, [], $taxonomy, false);

			if ( is_wp_error($result) ) {
				$failed[] = $item_id;
				continue;
			}

			// [internal]: per-item пара −1/+1 здесь больше не шлётся — дельту по
			// diff(старые, новые) термы применяет FolderCountLifecycle синхронно из хука
			// set_object_terms, который wp_set_object_terms() выше уже отстрелял.
			++$assigned;
		}

		$counts_recomputed = [];
		if ( $assigned > 0 ) {
			$counts_recomputed = array_values(array_unique(array_map('intval', $affected_folder_ids)));
			$this->count_service->invalidate($taxonomy);
			$this->cache->delete_group('folders_' . $taxonomy);
		}

		return [
			'assigned' => $assigned,
			'skipped' => $skipped,
			'failed' => $failed,
			'folder_id' => $folder_id,
			'taxonomy' => $taxonomy,
			'counts_recomputed' => $counts_recomputed,
		];
	}

	/**
	 * @param array<int|string> $item_ids
	 * @return array{moved: int, skipped: int, failed: array<int>, folder_id: int, taxonomy: string, counts_recomputed: array<int>, counts: array<int,int>}
	 */
	public function move_items_bulk(array $item_ids, int $folder_id, string $taxonomy): array {
		$moved = 0;
		$skipped = 0;
		$failed = [];
		$affected_folder_ids = [ $folder_id ];

		$use_folder = $folder_id > 0 && ! $this->repository->is_uncategorized_folder($folder_id, $taxonomy);
		$this->count_service->begin_bulk_write($taxonomy);
		wp_defer_term_counting(true);

		try {
			foreach ( array_chunk($item_ids, self::CHUNK_SIZE) as $chunk ) {
				foreach ( $chunk as $item_id ) {
					$item_id = (int) $item_id;
					if ( $item_id <= 0 ) {
						$failed[] = $item_id;
						continue;
					}

					if ( ! $this->authorize_item($item_id, $taxonomy) ) {
						$failed[] = $item_id;
						continue;
					}

					$current_terms = wp_get_object_terms($item_id, $taxonomy, [ 'fields' => 'ids' ]);
					if ( is_wp_error($current_terms) ) {
						$failed[] = $item_id;
						continue;
					}
					$current_terms = array_map('intval', (array) $current_terms);

					if ( $current_terms === [ $folder_id ] ) {
						++$skipped;
						continue;
					}

					foreach ( $current_terms as $tid ) {
						$affected_folder_ids[] = $tid;
					}

					$result = $use_folder
						? wp_set_object_terms($item_id, [ $folder_id ], $taxonomy)
						: wp_set_object_terms($item_id, [], $taxonomy, false);

					if ( is_wp_error($result) ) {
						$failed[] = $item_id;
						continue;
					}

					// [internal]: дельты ведёт FolderCountLifecycle по событию
					// set_object_terms (см. set_items() выше — тот же контракт).
					++$moved;
				}
			}
		} finally {
			wp_defer_term_counting(false);
			$this->count_service->end_bulk_write($taxonomy);
		}

		$counts_recomputed = [];
		$counts = [];
		if ( $moved > 0 ) {
			$counts_recomputed = array_values(array_unique(array_map('intval', $affected_folder_ids)));
			// end_bulk_write() already called delete_group() in the finally block above;
			// calling invalidate()/delete_group() again would double-bump the version counter.

			// [internal]: готовые счётчики затронутых папок в ответе — клиент применяет их
			// точечно вместо второго REST round-trip (refreshFolders). Вызов ПОСЛЕ
			// end_bulk_write() выше — кэш-группа уже провалидирована, get_counts_for()
			// прогревает её заново реальными значениями, а не значением, которое тут же
			// снесла бы отложенная инвалидация.
			$counts = $this->count_service->get_counts_for( $counts_recomputed, $taxonomy );
		}

		return [
			'moved' => $moved,
			'skipped' => $skipped,
			'failed' => $failed,
			'folder_id' => $folder_id,
			'taxonomy' => $taxonomy,
			'counts_recomputed' => $counts_recomputed,
			'counts' => $counts,
		];
	}

	/**
	 * Снять объекты со ВСЕХ папок таксономии, с per-item авторизацией
	 * ([internal], M2). Раньше mutation жила inline в
	 * MediaController::unassign_items без проверки прав — та же IDOR-дыра, что H1.
	 *
	 * [internal] ([internal]): раньше эта mutation вызывала
	 * wp_set_object_terms() напрямую, минуя MediaMoveOrchestrator::route() — единственный
	 * владелец trash-aware маршрутизации ([internal]). Trash-элемент оставался в Корзине
	 * с term=[] вместо восстановления ([internal] требует восстанавливать элемент при любой
	 * операции с term, включая «убрать из папки» — folder_id=FolderId::ROOT — не только явный
	 * target). Теперь unassign делегирует в route(), тот же путь, что уже используют
	 * AjaxRouter/AssignmentsApi move_items(folder_id=0); результат адаптируется обратно в
	 * прежний контракт {unassigned, failed}.
	 *
	 * @param array<int|string> $item_ids
	 * @return array{unassigned: int, failed: array<int>}
	 */
	public function unassign_items(array $item_ids, string $taxonomy): array {
		$ids = array_values( array_filter( array_map( 'intval', $item_ids ), static fn (int $id): bool => $id > 0 ) );
		$failed = array_values( array_diff( array_map( 'intval', $item_ids ), $ids ) );

		$authorized = [];
		foreach ( $ids as $item_id ) {
			if ( $this->authorize_item($item_id, $taxonomy) ) {
				$authorized[] = $item_id;
			} else {
				$failed[] = $item_id;
			}
		}

		if ( $authorized === [] ) {
			return [ 'unassigned' => 0, 'failed' => $failed ];
		}

		$result = MediaMoveOrchestrator::route( $authorized, FolderId::ROOT, $taxonomy );

		return [
			'unassigned' => $result->moved,
			'failed'     => array_merge( $failed, $result->failed ),
		];
	}

	/**
	 * Per-item авторизация мутации папочной таксономии ([internal], H1/M2).
	 *
	 * Гейт маршрута (RestController::check) — глобальный («может ли назначать вообще»), не
	 * per-item. Без этой проверки любой Upload-юзер переписывал бы папки на ЧУЖИХ объектах
	 * по ID (IDOR). Форма как MediaDeleteService::bulk_trash:
	 *  - объект существует;
	 *  - его тип соответствует taxonomy (нельзя повесить attachment-папку на post и наоборот);
	 *  - текущий актор вправе редактировать ЭТОТ объект (edit_post → WP-модель edit_others_posts:
	 *    Author/Contributor блокируются на чужом, Editor+ проходит by design).
	 */
	private function authorize_item(int $item_id, string $taxonomy): bool {
		$post = get_post($item_id);
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( TaxonomyResolver::fromPostType($post->post_type) !== $taxonomy ) {
			return false;
		}

		return current_user_can('edit_post', $item_id);
	}
}
