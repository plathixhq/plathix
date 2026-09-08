<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobLockService;

/**
 * Deletes all user-created media folders, preserving system folders.
 * Spec ref: section 21.
 */
final class FolderResetService
{
	public function __construct(
		private ?FolderRepository $folder_repository = null,
	) {
	}

	/**
	 * @param bool $skip_own_lock
	 * @return array{success: bool, removed: int, skipped: int, errors: int, locked?: bool}
	 */

	public function run(bool $skip_own_lock = false): array {
		$taxonomy = Taxonomy::taxonomyForPostType('attachment');
		if ( ! taxonomy_exists($taxonomy) ) {
			return [ 'success' => false, 'removed' => 0, 'skipped' => 0, 'errors' => 1 ];
		}

		if ( $skip_own_lock ) {
			return $this->runLocked( $taxonomy );
		}

		$lock_service = new JobLockService();
		$lock_name    = $this->lockName( $taxonomy );
		$lock         = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return [ 'success' => false, 'removed' => 0, 'skipped' => 0, 'errors' => 0, 'locked' => true ];
		}

		try {
			return $this->runLocked( $taxonomy );
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}
	}

	/**
	 * @return array{success: bool, removed: int, skipped: int, errors: int}
	 */
	private function runLocked(string $taxonomy): array {
		do_action( 'plathix/audit/record', 'preset_reset_started', [ 'taxonomy' => $taxonomy ]);

		$repo    = $this->getRepository();
		$all     = $repo->getAll($taxonomy);
		$removed = 0;
		$skipped = 0;
		$errors  = 0;

		// Step 1–2: Determine user-created folders, exclude protected ones.
		// Delete leaves first to avoid parent-before-child conflicts.
		// Sort by depth descending: deepest terms first.
		usort($all, static function (\WP_Term $a, \WP_Term $b): int {
			return strcmp($b->slug, $a->slug); // stable fallback; depth handled below
		});

		// Build a simple depth map
		$depth_map = [];
		foreach ( $all as $term ) {
			$depth_map[ (int) $term->term_id] = $this->termDepth($term, $all);
		}

		// Sort deepest first
		usort($all, static function (\WP_Term $a, \WP_Term $b) use ($depth_map): int {
			return $depth_map[ (int) $b->term_id] <=> $depth_map[ (int) $a->term_id];
		});

		foreach ( $all as $term ) {
			$slug = (string) $term->slug;

			if ( in_array($slug, FolderRepository::systemSlugs(), true) ) {
				$skipped++;
				continue;
			}

			$term_id = (int) $term->term_id;

			// Step 3: delete the folder (files stay, they lose their taxonomy assignment naturally)
			$deleted = $repo->delete($term_id, $taxonomy);
			if ( $deleted ) {
				// Step 4: term meta is removed automatically by wp_delete_term → delete_term_meta cascade.
				$removed++;
			} else {
				$errors++;
			}
		}

		// Step 5: clear related caches
		( new FolderCountService($repo, Cache::make()) )->invalidate($taxonomy);
		delete_option("{$taxonomy}_children");

		// Step 6: write audit log
			do_action( 'plathix/audit/record', 'preset_reset_completed', [
				'objectType' => 'preset',
				'itemsCount' => $removed,
				/* translators: 1: removed folders count, 2: skipped folders count, 3: error count. */
				'summary'     => sprintf(__('Folder structure reset: %1$d removed, %2$d skipped, %3$d errors.', 'plathix'), $removed, $skipped, $errors),
				'context'     => [ 'removed' => $removed, 'skipped' => $skipped, 'errors' => $errors ],
			]);

		return [
			'success' => $errors === 0,
			'removed' => $removed,
			'skipped' => $skipped,
			'errors'  => $errors,
		];
	}

	// -------------------------------------------------------------------------

	private function getRepository(): FolderRepository {
		return $this->folder_repository ?? new FolderRepository();
	}

	private function lockName(string $taxonomy): string {
		return 'plathix_pr_' . get_current_blog_id() . '_' . md5( $taxonomy );
	}

	/**
	 * @param \WP_Term[] $all_terms
	 */
	private function termDepth(\WP_Term $term, array $all_terms): int {

		$depth   = 0;
		$parent  = (int) $term->parent;
		$visited = [];

		while ( $parent > 0 ) {
			if ( isset( $visited[ $parent ] ) ) {
				break;
			}
			$visited[ $parent ] = true;

			$depth++;
			$found = null;
			foreach ( $all_terms as $t ) {
				if ( (int) $t->term_id === $parent ) {
					$found = $t;
					break;
				}
			}

			if ( $found === null ) {
				break;
			}

			$parent = (int) $found->parent;
		}

		return $depth;
	}
}
