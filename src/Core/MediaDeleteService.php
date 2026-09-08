<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;

final class MediaDeleteService
{


	/**
	 * Move a batch of attachments to WP trash.
	 *
	 * Returns three lists:
	 *   trashed  — IDs successfully moved to trash
	 *   failed   — IDs where permission was denied or wp_trash_post() returned false
	 *   skipped  — IDs that are invalid, not found, or not attachments
	 *
	 * @param int[] $ids
	 */
	public function bulkTrash(array $ids, string $taxonomy = PLATHIX_TAXONOMY): MediaTrashResult {
		$trashed = [];
		$failed  = [];
		$skipped = [];

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;

			if ( $id <= 0 ) {
				$skipped[] = $id;
				continue;
			}

			$post = get_post($id);
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
				$skipped[] = $id;
				continue;
			}

			if ( $post->post_status === 'trash' ) {
				$skipped[] = $id;
				continue;
			}

			if ( ! current_user_can('delete_post', $id) ) {
				$failed[] = $id;
				continue;
			}

			$lock = ( new MediaTrashLock() )->acquire( $id );
			if ( is_wp_error( $lock ) ) {
				$failed[] = $id;
				continue;
			}

			try {

				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' ) {
					$skipped[] = $id;
					continue;
				}

				$result = wp_trash_post($id);

				if ( $result !== false && $result !== null ) {
					$trashed[] = $id;
				} else {
					$failed[] = $id;
				}
			} finally {
				( new MediaTrashLock() )->release( $id, $lock['token'] ?? '' );
			}
		}

		if ( $trashed !== [] ) {
			Cache::onAttachmentChange(null, $taxonomy);
		}

		return new MediaTrashResult(
			trashed: $trashed,
			failed: $failed,
			skipped: $skipped
		);
	}

	/**
	 * Restore trashed attachments and assign them to the target folder.
	 *
	 * @param int[] $ids
	 */
	public function bulkRestore(array $ids, int $target_folder_id, string $taxonomy): MediaRestoreResult {
		$restored = [];
		$failed   = [];
		$skipped  = [];
		$repo     = new FolderRepository();

		$primed_ids = array_values( array_filter( array_map( 'intval', $ids ), static fn (int $id): bool => $id > 0 ) );
		if ( $primed_ids !== [] ) {
			_prime_post_caches( $primed_ids, false, false );
			update_object_term_cache( $primed_ids, $taxonomy );
		}

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;

			if ( $id <= 0 ) {
				$skipped[] = $id;
				continue;
			}

			$post = get_post($id);
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
				$skipped[] = $id;
				continue;
			}

			if ( $post->post_status !== 'trash' ) {
				$skipped[] = $id;
				continue;
			}

			if ( ! current_user_can('delete_post', $id) ) {
				$failed[] = $id;
				continue;
			}

			$lock = ( new MediaTrashLock() )->acquire( $id );
			if ( is_wp_error( $lock ) ) {
				$failed[] = $id;
				continue;
			}

			try {

				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || $post->post_status !== 'trash' ) {
					$skipped[] = $id;
					continue;
				}

				$result = wp_untrash_post($id);
				if ( $result === false || $result === null ) {
					$failed[] = $id;
					continue;
				}

				$folder_id     = $this->resolveRestoreTarget( $id, $target_folder_id, $taxonomy, $repo );
				$terms_result  = $folder_id > 0
					? wp_set_object_terms($id, [ $folder_id ], $taxonomy)
					: wp_set_object_terms($id, [], $taxonomy, false);
				if ( is_wp_error( $terms_result ) ) {
					$failed[] = $id;
					continue;
				}

				$restored[] = $id;
			} finally {
				( new MediaTrashLock() )->release( $id, $lock['token'] ?? '' );
			}
		}

		if ( $restored !== [] ) {
			Cache::onAttachmentChange(null, $taxonomy);
		}

		return new MediaRestoreResult(
			restored: $restored,
			failed: $failed,
			skipped: $skipped
		);
	}

	/**
	 * @param int              $file_id
	 * @param int              $target_folder_id
	 * @param string           $taxonomy
	 * @param FolderRepository $repo
	 * @return int
	 */

	private function resolveRestoreTarget(int $file_id, int $target_folder_id, string $taxonomy, FolderRepository $repo): int {

		if ( $target_folder_id > FolderId::ROOT && $this->isLiveUserFolder( $target_folder_id, $taxonomy, $repo ) ) {
			return $target_folder_id;
		}

		$current = wp_get_object_terms( $file_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( ! is_wp_error( $current ) ) {
			foreach ( (array) $current as $term_id ) {
				$term_id = (int) $term_id;
				if ( $this->isLiveUserFolder( $term_id, $taxonomy, $repo ) ) {
					return $term_id;
				}
			}
		}

		return 0;
	}

	private function isLiveUserFolder(int $term_id, string $taxonomy, FolderRepository $repo): bool {
		if ( $term_id <= FolderId::ROOT ) {
			return false;
		}
		if ( ! $repo->getById( $term_id, $taxonomy ) instanceof \WP_Term ) {
			return false;

		}
		if ( $repo->isUncategorizedFolder( $term_id, $taxonomy ) || $term_id === TrashFolder::id( $taxonomy ) ) {
			return false;

		}

		if ( in_array( $term_id, HiddenFolders::ids( $taxonomy ), true ) ) {
			return false;

		}
		return true;
	}
}
