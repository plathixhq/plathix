<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;

/**
 * Applies a registered preset to the Media Library.
 * Spec ref: sections 18, 19, 20.
 */
final class PresetApplyPipeline
{
	public function __construct(
		private readonly PresetRepository $preset_repository = new PresetRepository(),
		private readonly ConflictSuffixGenerator $conflict_generator = new ConflictSuffixGenerator(),
		private ?FolderTreeService $folder_tree_service = null,
	) {
	}

	/**
	 * @param bool $start_from_scratch  If true, wipes user-created folders before applying.
	 * @return array{success: bool, created: int, errors: int, scope: string, error?: array<string, mixed>}
	 */
	public function run(int $preset_id, bool $start_from_scratch = false): array {
		// Step 1: load preset
		$preset = $this->preset_repository->find($preset_id);
		if ( $preset === null ) {
			return $this->fail(new PresetError('preset_not_found', __('Preset not found.', 'plathix'), null, null, true));
		}

		// Step 2: validate availability
		if ( ($preset['validation_status'] ?? '') !== 'valid' ) {
			return $this->fail(new PresetError('preset_not_valid', __('Preset is not valid and cannot be applied.', 'plathix'), null, null, true));
		}

		// Step 3: resolve scope — MVP always Media Library
		$taxonomy = Taxonomy::taxonomyForPostType('attachment');
		if ( ! taxonomy_exists($taxonomy) ) {
			return $this->fail(new PresetError('preset_taxonomy_unavailable', __('Media taxonomy is not registered.', 'plathix'), null, null, true));
		}

		$lock_service = new JobLockService();
		$lock_name    = $this->lockName( $taxonomy );
		$lock         = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $lock['mode'] ) {
			return $this->fail(new PresetError('preset_locked', __('Preset apply is temporarily locked by another request. Please try again in a moment.', 'plathix'), null, null, true));
		}

		try {
			return $this->runLocked($preset, $preset_id, $taxonomy, $start_from_scratch);
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}
	}

	/**
	 * @param array<string, mixed> $preset
	 * @return array{success: bool, created: int, errors: int, scope: string, error?: array<string, mixed>}
	 */
	private function runLocked(array $preset, int $preset_id, string $taxonomy, bool $start_from_scratch): array {
		$tree = $this->getFolderTreeService();

		// Step 4: optionally reset
		if ( $start_from_scratch ) {
			$reset = ( new FolderResetService() )->run(skip_own_lock: true);
			if ( ! $reset['success'] ) {
				return $this->fail(new PresetError('preset_reset_failed', __('Failed to reset folder structure before applying preset.', 'plathix'), null, null, true));
			}
		}

		// Step 5: build depth → term_id map
		$structure = (array) ($preset['structure'] ?? []);
		$created   = 0;
		$errors    = 0;

		$depth_map = [];

		$favorite_term_ids = [];

		// Step 6: create folders in file order
		foreach ( $structure as $entry ) {
			$depth = (int) ($entry['depth'] ?? 0);
			$name      = (string) ($entry['name'] ?? '');
			$color     = (string) ($entry['color'] ?? 'default');

			// Determine parent term_id
			$parent_id = 0;
			if ( $depth > 0 ) {
				if ( ! isset($depth_map[ $depth - 1 ]) ) {
					$errors++;
					continue;
				}
				$parent_id = $depth_map[ $depth - 1 ];
			}

			// Conflict resolution (spec sec 20)
			$resolved_name = $this->conflict_generator->resolve($name, $parent_id, $taxonomy);

			$term_id = $tree->create($resolved_name, $parent_id, $taxonomy);
			if ( is_wp_error($term_id) ) {
				$errors++;

				unset($depth_map[ $depth ]);
				continue;
			}
			/**
			 * @var int $term_id
			 */


			$depth_map[ $depth ] = $term_id;

			foreach ( array_keys($depth_map) as $known_depth ) {
				if ( $known_depth > $depth ) {
					unset($depth_map[ $known_depth ]);
				}
			}
			$created++;

			if ( ! empty($entry['favorite']) ) {
				$favorite_term_ids[] = (int) $term_id;
			}

			// Step 7: assign color meta

			//

			if ( $color !== 'default' && $color !== '' ) {
				$sanitized_color = sanitize_hex_color($color);
				$old_color       = get_term_meta($term_id, PLATHIX_TERM_COLOR, true);
				$color_written   = update_term_meta($term_id, PLATHIX_TERM_COLOR, $sanitized_color);

				if ( ! $color_written && $old_color !== $sanitized_color ) {
					Logger::error('preset_apply_color_meta_write_failed', ['term_id' => $term_id]);
				}
			}
		}

		if ( $favorite_term_ids !== [] ) {
			$user_id = get_current_user_id();

			if ( $start_from_scratch ) {

				\Plathix\User\Preferences::setFavorites( $user_id, $favorite_term_ids, 'attachment' );
			} else {

				\Plathix\User\Preferences::mergeFavorites( $user_id, $favorite_term_ids, 'attachment' );
			}
		}

		// Step 8: invalidate caches (FolderTreeService::create already does count invalidation;
		// delete the taxonomy children cache so WP rebuilds it)
		delete_option("{$taxonomy}_children");

		// Step 9: update last applied timestamp
		$this->preset_repository->update($preset_id, [
			'last_applied_at' => gmdate('Y-m-d H:i:s'),
		]);

		// Step 9 (audit)
			do_action( 'plathix/audit/record', 'preset_applied', [
				'objectType' => 'preset',
				'objectId'   => $preset_id,
				'itemsCount' => $created,
				/* translators: 1: preset title, 2: created folders count. */
				'summary'     => sprintf(__('Preset "%1$s" applied (%2$d folders created).', 'plathix'), $preset['title'], $created),
				'context'     => [
					'slug'    => $preset['slug'],
					'scope'   => 'media',
				'created' => $created,
				'errors'  => $errors,
				],
			]);

		// Step 10: result payload (spec sec 28.2)
		return [
			'success' => $errors === 0,
			'created' => $created,
			'errors'  => $errors,
			'scope'   => 'media',
		];
	}

	// -------------------------------------------------------------------------

	private function lockName(string $taxonomy): string {
		return 'plathix_pr_' . get_current_blog_id() . '_' . md5( $taxonomy );
	}

	private function getFolderTreeService(): FolderTreeService {
		if ( $this->folder_tree_service !== null ) {
			return $this->folder_tree_service;
		}

		$repo  = new FolderRepository();
		$count = new FolderCountService($repo, Cache::make());
		return new FolderTreeService($repo, $count);
	}

	/** @return array{success: bool, created: int, errors: int, scope: string, error: array<string, mixed>} */
	private function fail(PresetError $error): array {
		return [
			'success' => false,
			'created' => 0,
			'errors'  => 0,
			'scope'   => 'media',
			'error'   => $error->toArray(),
		];
	}
}
