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
	 * @param bool $skip_own_lock Пропустить собственный lock take/release — вызывающий код
	 *                            (PresetApplyPipeline::run()) уже держит лок того же
	 *                            $taxonomy на момент вложенного вызова ([internal]: два
	 *                            параллельных read-snapshot-then-delete процесса без lock
	 *                            давали ложный partial failure, подтверждено runtime-тестом).
	 * @return array{success: bool, removed: int, skipped: int, errors: int, locked?: bool}
	 */
	public function run(bool $skip_own_lock = false): array {
		$taxonomy = Taxonomy::taxonomy_for_post_type('attachment');
		if ( ! taxonomy_exists($taxonomy) ) {
			return [ 'success' => false, 'removed' => 0, 'skipped' => 0, 'errors' => 1 ];
		}

		if ( $skip_own_lock ) {
			return $this->run_locked( $taxonomy );
		}

		$lock_service = new JobLockService();
		$lock_name    = $this->lock_name( $taxonomy );
		$lock         = $lock_service->acquire_order( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return [ 'success' => false, 'removed' => 0, 'skipped' => 0, 'errors' => 0, 'locked' => true ];
		}

		try {
			return $this->run_locked( $taxonomy );
		} finally {
			$lock_service->release_order( $lock_name, $lock );
		}
	}

	/**
	 * @return array{success: bool, removed: int, skipped: int, errors: int}
	 */
	private function run_locked(string $taxonomy): array {
		do_action( 'plathix/audit/record', 'preset_reset_started', [ 'taxonomy' => $taxonomy ]);

		$repo    = $this->get_repository();
		$all     = $repo->get_all($taxonomy);
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
			$depth_map[ (int) $term->term_id] = $this->term_depth($term, $all);
		}

		// Sort deepest first
		usort($all, static function (\WP_Term $a, \WP_Term $b) use ($depth_map): int {
			return $depth_map[ (int) $b->term_id] <=> $depth_map[ (int) $a->term_id];
		});

		foreach ( $all as $term ) {
			$slug = (string) $term->slug;

			// Step 2: skip protected system folders (единый источник — FolderRepository::system_slugs, [internal])
			if ( in_array($slug, FolderRepository::system_slugs(), true) ) {
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

	private function get_repository(): FolderRepository {
		return $this->folder_repository ?? new FolderRepository();
	}

	/**
	 * Собственный lock-scope ([internal]) — не пересекается с
	 * FolderTreeService::structure_lock_name() (`plathix_mv_*`, move/reorder): разные
	 * операции, разные владельцы, лок-граф остаётся ацикличным.
	 */
	private function lock_name(string $taxonomy): string {
		return 'plathix_pr_' . get_current_blog_id() . '_' . md5( $taxonomy );
	}

	/**
	 * @param \WP_Term[] $all_terms
	 */
	private function term_depth(\WP_Term $term, array $all_terms): int {
		$depth  = 0;
		$parent = (int) $term->parent;

		while ( $parent > 0 ) {
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
