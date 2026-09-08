<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Core\AttachmentVisibility;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderDTO;
use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\Cache;

class FolderStatsService
{
	private ?FolderCountService $folderService;

	public function __construct(?FolderCountService $folderService = null) {

		$this->folderService = $folderService;
	}

	private function folderService(): FolderCountService {
		return $this->folderService
			??= new FolderCountService( new FolderRepository(), Cache::make() );
	}

	/**
	 * @param string[] $post_types
	 * @return array{
	 *   total_folders:int, total_files:int,
	 *   distribution:array<int,array{label:string,folders:int,files:int}>,
	 *   maxDepth:int, orphaned_files:int, uncategorized_folder_id:int
	 * }
	 */
	public function collect(array $post_types): array {

		$sorted    = $post_types;
		sort( $sorted );
		$cache     = Cache::make();
		$cache_key = $cache->versionedKey( Cache::DASHBOARD_STATS_GROUP, 'folder_stats_' . md5( implode( ',', $sorted ) ) );
		$cached    = $cache->get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['total_folders'] ) ) {
			/** @var array{total_folders: int, total_files: int, distribution: array<int, array{label: string, folders: int, files: int}>, maxDepth: int, orphaned_files: int, uncategorized_folder_id: int} $cached */
			return $cached;
		}

		$total_folders = 0;
		$total_files   = 0;
		$distribution  = [];
		$parent_map    = [];

		$files_failed = false;

		foreach ( $post_types as $pt ) {
			$taxonomy = TaxonomyResolver::fromPostType( $pt );
			$folders  = $this->folderService()->getAllCached( $taxonomy );

			$real_folders = array_filter( $folders, static fn(FolderDTO $f): bool => ! $f->isProtected );
			$pt_folders   = count( $real_folders );
			$total_folders += $pt_folders;

			$raw_pt_total = $this->countPublishedPosts( $pt );
			if ( null === $raw_pt_total ) {
				$files_failed = true;
			}
			$pt_total = $raw_pt_total ?? 0;
			$total_files += $pt_total;

			if ( $pt_folders > 0 ) {
				$distribution[] = [
					'label'   => $this->postTypeLabel( $pt ),
					'folders' => $pt_folders,
					'files'   => $pt_total,
				];
			}

			foreach ( $folders as $f ) {

				$parent_map[ (int) $f->id ] = $f->parentId;
			}
		}

		$raw_orphaned    = $this->countOrphanedAttachments();
		$orphaned_failed = null === $raw_orphaned;

		$result = [
			'total_folders'           => $total_folders,
			'total_files'             => $total_files,
			'distribution'            => $distribution,
			'maxDepth'               => $this->maxDepth( $parent_map ),
			'orphaned_files'          => $raw_orphaned ?? 0,
			'uncategorized_folder_id' => ( new FolderRepository() )->getUncategorizedTermId( PLATHIX_TAXONOMY ),
		];

		if ( ! $orphaned_failed && ! $files_failed ) {
			$cache->set( $cache_key, $result, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/** @param array<int,int> $parent_map term_id => parent_id */
	private function maxDepth(array $parent_map): int {
		$maxDepth = 0;
		foreach ( array_keys( $parent_map ) as $id ) {
			$depth   = 0;
			$current = $id;
			$visited = [];
			while ( isset( $parent_map[ $current ] ) && $parent_map[ $current ] !== 0 ) {
				if ( isset( $visited[ $current ] ) ) {
					break;
				}
				$visited[ $current ] = true;
				$current = $parent_map[ $current ];
				++$depth;
			}
			if ( $depth > $maxDepth ) {
				$maxDepth = $depth;
			}
		}
		return $maxDepth;
	}

	private function countPublishedPosts(string $post_type): ?int {
		if ( 'attachment' === $post_type ) {

			return AttachmentVisibility::countVisibleOrNull( [ 'inherit', 'private' ] );
		}
		$counts = wp_count_posts( $post_type );
		return (int) ( $counts->publish ?? 0 );
	}

	private function countOrphanedAttachments(): ?int {
		$uncategorized_id = ( new FolderRepository() )->getUncategorizedTermId( PLATHIX_TAXONOMY );

		return $this->folderService()->getCount( $uncategorized_id, PLATHIX_TAXONOMY );
	}

	private function postTypeLabel(string $pt): string {
		$obj = get_post_type_object( $pt );
		return $obj?->labels->name ?? $pt;
	}
}
