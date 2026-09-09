<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Core\FolderCountCalculator;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;
use Plathix\Core\TrashFolder;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;

final class FolderCountReconcileJobRunner
{
	public const CHUNK_SIZE = 500;

	public const LAST_RUN_OPTION = 'plathix_fc_reconcile_last_run';

	public const LOCK_BUSY_LOGGED_OPTION = 'plathix_fc_reconcile_lock_busy_last_logged';

	private JobLockService $lock_service;
	private FolderCountCalculator $calculator;
	private ?FolderCountService $countService;

	public function __construct(?JobLockService $lock_service = null, ?FolderCountService $countService = null)
	{
		$this->lock_service  = $lock_service ?? new JobLockService();
		$this->calculator    = new FolderCountCalculator();
		$this->countService = $countService;
	}

	private function countService(): FolderCountService
	{
		return $this->countService ??= new FolderCountService( new FolderRepository(), Cache::make() );
	}

	/** @param array<string, mixed> $args */
	public function run(array $args, callable $runInBlogContext): void
	{
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$runInBlogContext(
			$blog_id,
			function (): void {
				$this->reconcileAll();
			}
		);
	}

	public function reconcileAll(): void
	{
		$fingerprint = 'fc_reconcile_' . get_current_blog_id();
		$lock        = $this->lock_service->acquireExecution( $fingerprint );
		if ( ! ( $lock['acquired'] ?? false ) ) {
			$last_logged = (int) get_option( self::LOCK_BUSY_LOGGED_OPTION, 0 );
			if ( time() - $last_logged >= DAY_IN_SECONDS ) {
				Logger::warning( 'folder_count_reconcile_lock_busy', [ 'fingerprint' => $fingerprint ] );
				update_option( self::LOCK_BUSY_LOGGED_OPTION, time(), false );
			}
			return;
		}

		try {
			$taxonomies = array_values(
				array_filter(
					get_taxonomies(),
					static fn (string $taxonomy): bool => TaxonomyResolver::isPlathixTaxonomy( $taxonomy )
				)
			);

			$completed = true;
			foreach ( $taxonomies as $taxonomy ) {
				if ( ! $this->reconcileTaxonomy( (string) $taxonomy ) ) {
					$completed = false;
				}
			}

			if ( $completed ) {
				$old_run    = get_option( self::LAST_RUN_OPTION, 0 );
				$now        = time();
				$run_marked = update_option( self::LAST_RUN_OPTION, $now, false );

				if ( ! $run_marked && $old_run !== $now ) {
					Logger::error( 'folder_count_reconcile_last_run_write_failed' );
				}
			}
		} finally {
			$this->lock_service->releaseExecution( $fingerprint );
		}
	}

	private function reconcileTaxonomy(string $taxonomy): bool
	{
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>parent',
			]
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) || $terms === [] ) {
			return true;
		}

		$repository       = new FolderRepository();
		$uncategorized_id = $repository->getUncategorizedTermId( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );

		/**
		 * @var array<int, int> $parents
		 */

		$parents = [];
		foreach ( $terms as $term_id => $parent_id ) {
			$term_id = (int) $term_id;
			if ( $term_id === $uncategorized_id || $term_id === $trash_id ) {
				continue;
			}
			$parents[ $term_id ] = (int) $parent_id;
		}
		if ( $parents === [] ) {
			return true;
		}

		$ids = array_keys( $parents );

		$direct = [];
		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
			$counts = $this->calculator->batchCounts( $chunk, $taxonomy );
			if ( null === $counts ) {
				return false;
			}
			foreach ( $chunk as $id ) {
				$direct[ $id ] = $counts[ $id ] ?? 0;
			}
		}

		$children = [];
		foreach ( $parents as $id => $parent_id ) {
			if ( $parent_id > 0 && isset( $parents[ $parent_id ] ) ) {
				$children[ $parent_id ][] = $id;
			}
		}

		$recursive   = [];
		$in_progress = [];
		$resolve     = function (int $id) use (&$resolve, &$recursive, &$in_progress, $children, $direct): int {
			if ( isset( $recursive[ $id ] ) ) {
				return $recursive[ $id ];
			}
			if ( isset( $in_progress[ $id ] ) ) {
				return $direct[ $id ] ?? 0;
			}
			$in_progress[ $id ] = true;

			$sum = $direct[ $id ] ?? 0;
			foreach ( $children[ $id ] ?? [] as $child_id ) {
				$sum += $resolve( $child_id );
			}

			unset( $in_progress[ $id ] );
			return $recursive[ $id ] = $sum;
		};
		foreach ( $ids as $id ) {
			$resolve( $id );
		}

		if ( function_exists( 'update_termmeta_cache' ) ) {
			foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
				update_termmeta_cache( $chunk );
			}
		}

		$writes = 0;
		foreach ( $ids as $id ) {
			$raw     = get_term_meta( $id, '_plathix_folder_count_recursive', true );
			$current = ( '' !== $raw && is_numeric( $raw ) ) ? max( 0, (int) $raw ) : null;
			if ( $current === $recursive[ $id ] ) {
				continue;
			}
			$this->countService()->overwriteRecursiveCount( $id, $recursive[ $id ] );
			++$writes;
		}

		if ( $writes > 0 ) {
			$this->countService()->invalidate( $taxonomy );
		}

		return true;
	}
}
