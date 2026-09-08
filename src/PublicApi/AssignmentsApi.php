<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\MediaMoveOrchestrator;
use Plathix\Core\MediaTrashRunner;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\Cache;

final class AssignmentsApi
{
	/** @var \Closure(array<int, int>, int, string): array<string, mixed> */
	private \Closure $items_mover;
	/** @var \Closure(int, string, string, int, int, array<int, string>): array<string, mixed> */
	private \Closure $items_loader;
	/** @var \Closure(array<int, int>, string): array<string, mixed> */
	private \Closure $media_trash;
	/** @var \Closure(array<int, int>, int, string): array<string, mixed> */
	private \Closure $media_restore;
	/** @var \Closure(array<int, int>, int, string): array<string, mixed> */
	private \Closure $items_assigner;
	/** @var \Closure(array<int, int>, string): array<string, mixed> */
	private \Closure $items_unassigner;

	public function __construct(
		?callable $items_mover = null,
		?callable $items_loader = null,
		?callable $media_trash = null,
		?callable $media_restore = null,
		?callable $items_assigner = null,
		?callable $items_unassigner = null
	) {
		$this->items_mover = \Closure::fromCallable($items_mover ?? [$this, 'defaultItemsMover']);
		$this->items_loader = \Closure::fromCallable($items_loader ?? [$this, 'defaultItemsLoader']);
		$this->media_trash = \Closure::fromCallable($media_trash ?? [$this, 'defaultMediaTrash']);
		$this->media_restore = \Closure::fromCallable($media_restore ?? [$this, 'defaultMediaRestore']);
		$this->items_assigner = \Closure::fromCallable($items_assigner ?? [$this, 'defaultItemsAssigner']);
		$this->items_unassigner = \Closure::fromCallable($items_unassigner ?? [$this, 'defaultItemsUnassigner']);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */

	public function assignItems(array $ids, int $folderId, string $taxonomy): array
	{
		return ($this->items_assigner)($ids, $folderId, $taxonomy);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public function moveItems(array $ids, int $folderId, string $taxonomy): array
	{
		return ($this->items_mover)($ids, $folderId, $taxonomy);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */

	public function unassignItems(array $ids, string $taxonomy): array
	{
		return ($this->items_unassigner)($ids, $taxonomy);
	}

	/**
	 * @param array<int, string> $fields
	 * @return array<string, mixed>
	 */
	public function getFolderItems(int $folderId, string $postType = 'attachment', int $page = 1, int $perPage = 50, array $fields = []): array
	{
		return ($this->items_loader)($folderId, $postType, TaxonomyResolver::fromPostType($postType), $page, $perPage, $fields);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public function trashMedia(array $ids): array
	{
		return ($this->media_trash)($ids, TaxonomyResolver::fromPostType('attachment'));
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	public function restoreMedia(array $ids, int $targetFolderId = 0): array
	{
		return ($this->media_restore)($ids, $targetFolderId, TaxonomyResolver::fromPostType('attachment'));
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */

	private function defaultItemsMover(array $ids, int $folderId, string $taxonomy): array
	{
		return MediaMoveOrchestrator::route($ids, $folderId, $taxonomy)->toArray();
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	private function defaultItemsUnassigner(array $ids, string $taxonomy): array
	{
		$repository = new FolderRepository();
		$cache = Cache::make();
		$countService = new FolderCountService($repository, $cache);
		$assignment = new FolderAssignmentService($repository, $countService, $cache);

		return $assignment->unassignItems($ids, $taxonomy);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */
	private function defaultItemsAssigner(array $ids, int $folderId, string $taxonomy): array
	{
		$repository = new FolderRepository();
		$cache = Cache::make();
		$countService = new FolderCountService($repository, $cache);
		$assignment = new FolderAssignmentService($repository, $countService, $cache);

		return $assignment->setItems($ids, $folderId, $taxonomy);
	}

	/**
	 * @param array<int, string> $fields
	 * @return array<string, mixed>
	 */

	private function defaultItemsLoader(int $folderId, string $postType, string $taxonomy, int $page, int $perPage, array $fields): array
	{
		return ( new \Plathix\Core\FolderItemsLoader() )->load( $folderId, $postType, $taxonomy, $page, $perPage, $fields );
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */

	private function defaultMediaTrash(array $ids, string $taxonomy): array
	{
		/** @var callable(array<int,int>, string): array<string,mixed> $runner */
		$runner = apply_filters('plathix/media/trash_runner', MediaTrashRunner::trash(...));

		return $runner($ids, $taxonomy);
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<string, mixed>
	 */

	private function defaultMediaRestore(array $ids, int $targetFolderId, string $taxonomy): array
	{
		/** @var callable(array<int,int>, int, string): array<string,mixed> $runner */
		$runner = apply_filters('plathix/media/restore_runner', MediaTrashRunner::restore(...));

		return $runner($ids, $targetFolderId, $taxonomy);
	}
}
