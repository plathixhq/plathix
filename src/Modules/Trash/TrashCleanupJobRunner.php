<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaDeleteService;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\Cache;

final class TrashCleanupJobRunner
{
	private const META_KEY = '_plathix_trash_time';
	private const DEFAULT_DAYS = 30;

	private const MAX_ITEMS_PER_RUN = 100;

	private $folder_cleanup;

	/**
	 * @param (callable(int): void)|null $folder_cleanup
	 */

	public function __construct(?callable $folder_cleanup = null)
	{
		$this->folder_cleanup = $folder_cleanup ?? [ $this, 'cleanupFolders' ];
	}

	/**
	 * @param array<string, mixed> $args
	 * @param callable(int, callable): mixed $runInBlogContext
	 */

	public function run(array $args, callable $runInBlogContext): void
	{
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$runInBlogContext(
			$blog_id,
			function (): void {
				$days   = $this->retentionDays();
				$cutoff = time() - ( $days * DAY_IN_SECONDS );

				( $this->folder_cleanup )( $cutoff );

				$ids = get_posts(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'trash',
						'posts_per_page' => self::MAX_ITEMS_PER_RUN,
						'fields'         => 'ids',
						'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the trash-timestamp meta is the only way to find expired items; runs in a scheduled background job, never on a user request
							[
								'key'     => self::META_KEY,
								'value'   => $cutoff,
								'compare' => '<',
								'type'    => 'NUMERIC',
							],
						],
						'no_found_rows'  => true,
					]
				);

				foreach ( (array) $ids as $id ) {
					$id = (int) $id;

					$post = get_post( $id );
					if ( ! $post || 'trash' !== $post->post_status ) {
						continue;
					}

					( new MediaDeleteService() )->permanentDelete( $id );
				}
			}
		);
	}

	public function cleanupFolders(int $cutoff): void
	{
		$repository = new FolderRepository();
		$tree       = new FolderTreeService( $repository, new FolderCountService( $repository, Cache::make() ) );

		foreach ( Taxonomy::getEnabledTaxonomies() as $taxonomy ) {
			$lock = $tree->acquireStructureLock( $taxonomy );
			if ( 'none' === $lock['mode'] ) {
				continue;
			}

			try {

				$ids = array_slice( $repository->getTrashedIds( $taxonomy, FolderTrashService::META_TIME ), 0, self::MAX_ITEMS_PER_RUN );
				foreach ( $ids as $id ) {

					$trashed_at = (int) $repository->getMeta( $id, FolderTrashService::META_TIME );
					if ( $trashed_at <= 0 || $trashed_at >= $cutoff ) {
						continue;
					}
					$tree->deleteRecursiveUnderLock( $id, $taxonomy, 'delete' );
				}
			} finally {
				$tree->releaseStructureLock( $taxonomy, $lock );
			}
		}
	}

	private function retentionDays(): int
	{
		$days = (int) get_option( 'plathix_trash_retention_days', self::DEFAULT_DAYS );

		return max( 1, min( 180, $days ) );
	}
}
