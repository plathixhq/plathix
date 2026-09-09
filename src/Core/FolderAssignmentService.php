<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;

final class FolderAssignmentService
{
	private const CHUNK_SIZE = 500;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $countService,
		// @phpstan-ignore property.onlyWritten

		private readonly Cache $cache
	) {
	}

	/**
	 * @param array<int|string> $item_ids
	 * @return array{assigned: int, skipped: int, failed: array<int>, folder_id: int, taxonomy: string, counts_recomputed: array<int>}
	 */
	public function setItems(array $item_ids, int $folder_id, string $taxonomy): array {
		$assigned = 0;
		$skipped = 0;
		$failed = [];
		$affected_folder_ids = [ $folder_id ];
		$use_folder = $folder_id > 0 && ! $this->repository->isUncategorizedFolder($folder_id, $taxonomy);

		foreach ( $item_ids as $item_id ) {
			$item_id = (int) $item_id;
			if ( $item_id <= 0 ) {
				$failed[] = $item_id;
				continue;
			}

			if ( ! $this->authorizeItem($item_id, $taxonomy) ) {
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

			++$assigned;
		}

		$counts_recomputed = [];
		if ( $assigned > 0 ) {
			$counts_recomputed = array_values(array_unique(array_map('intval', $affected_folder_ids)));

			$this->countService->invalidate($taxonomy);
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
	public function moveItemsBulk(array $item_ids, int $folder_id, string $taxonomy): array {
		$moved = 0;
		$skipped = 0;
		$failed = [];
		$affected_folder_ids = [ $folder_id ];

		$use_folder = $folder_id > 0 && ! $this->repository->isUncategorizedFolder($folder_id, $taxonomy);
		$this->countService->beginBulkWrite($taxonomy);
		wp_defer_term_counting(true);

		try {
			foreach ( array_chunk($item_ids, self::CHUNK_SIZE) as $chunk ) {

				$primed_ids = array_values( array_filter( array_map( 'intval', $chunk ), static fn (int $id): bool => $id > 0 ) );
				if ( $primed_ids !== [] ) {
					_prime_post_caches( $primed_ids, false, false );
					update_object_term_cache( $primed_ids, $taxonomy );
				}

				foreach ( $chunk as $item_id ) {
					$item_id = (int) $item_id;
					if ( $item_id <= 0 ) {
						$failed[] = $item_id;
						continue;
					}

					if ( ! $this->authorizeItem($item_id, $taxonomy) ) {
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

					++$moved;
				}
			}
		} finally {
			wp_defer_term_counting(false);
			$this->countService->endBulkWrite($taxonomy);
		}

		$counts_recomputed = [];
		$counts = [];
		if ( $moved > 0 ) {
			$counts_recomputed = array_values(array_unique(array_map('intval', $affected_folder_ids)));
			// endBulkWrite() already called deleteGroup() in the finally block above;
			// calling invalidate()/deleteGroup() again would double-bump the version counter.

			$counts = $this->countService->getCountsFor( $counts_recomputed, $taxonomy );
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
	 * @param array<int|string> $item_ids
	 * @return array{unassigned: int, failed: array<int>}
	 */

	public function unassignItems(array $item_ids, string $taxonomy): array {
		$ids = array_values( array_filter( array_map( 'intval', $item_ids ), static fn (int $id): bool => $id > 0 ) );
		$failed = array_values( array_diff( array_map( 'intval', $item_ids ), $ids ) );

		$authorized = [];
		foreach ( $ids as $item_id ) {
			if ( $this->authorizeItem($item_id, $taxonomy) ) {
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

	private function authorizeItem(int $item_id, string $taxonomy): bool {
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
