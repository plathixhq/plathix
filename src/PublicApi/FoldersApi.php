<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderDTO;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\Cache;

final class FoldersApi
{
	/** @var \Closure(string): array<int, array<string, mixed>> */
	private \Closure $folders_loader;
	/** @var \Closure(int, string): (array<string, mixed>|null) */
	private \Closure $folder_loader;
	/** @var \Closure(string, int, string): (int|\WP_Error) */
	private \Closure $folder_creator;
	/** @var \Closure(int, string, string): (bool|\WP_Error) */
	private \Closure $folder_renamer;
	/** @var \Closure(int, int, string): (bool|\WP_Error) */
	private \Closure $folder_mover;
	/** @var \Closure(int, string, string): bool */
	private \Closure $folder_deleter;
	/** @var \Closure(array<int, array<string, mixed>>, string): array<string, mixed> */
	private \Closure $folders_reorderer;

	public function __construct(
		?callable $folders_loader = null,
		?callable $folder_loader = null,
		?callable $folder_creator = null,
		?callable $folder_renamer = null,
		?callable $folder_mover = null,
		?callable $folder_deleter = null,
		?callable $folders_reorderer = null
	) {
		$this->folders_loader = \Closure::fromCallable(
			$folders_loader ?? [$this, 'defaultFoldersLoader']
		);
		$this->folder_loader = \Closure::fromCallable(
			$folder_loader ?? [$this, 'defaultFolderLoader']
		);
		$this->folder_creator = \Closure::fromCallable($folder_creator ?? [$this, 'defaultFolderCreator']);
		$this->folder_renamer = \Closure::fromCallable($folder_renamer ?? [$this, 'defaultFolderRenamer']);
		$this->folder_mover = \Closure::fromCallable($folder_mover ?? [$this, 'defaultFolderMover']);
		$this->folder_deleter = \Closure::fromCallable($folder_deleter ?? [$this, 'defaultFolderDeleter']);
		$this->folders_reorderer = \Closure::fromCallable($folders_reorderer ?? [$this, 'defaultFoldersReorderer']);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getFolders(string $postType = 'attachment'): array
	{
		return ($this->folders_loader)(TaxonomyResolver::fromPostType($postType));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getFolder(int $id, string $taxonomy): ?array
	{
		return ($this->folder_loader)($id, $taxonomy);
	}

	public function createFolder(string $name, int $parent, string $taxonomy): int|\WP_Error
	{
		return ($this->folder_creator)($name, $parent, $taxonomy);
	}

	public function renameFolder(int $id, string $name, string $taxonomy): bool|\WP_Error
	{
		return ($this->folder_renamer)($id, $name, $taxonomy);
	}

	public function moveFolder(int $id, int $parent, string $taxonomy): bool|\WP_Error
	{
		return ($this->folder_mover)($id, $parent, $taxonomy);
	}

	public function deleteFolder(int $id, string $taxonomy, string $onChildren = 'reattach'): bool
	{
		return (bool) ($this->folder_deleter)($id, $taxonomy, $onChildren);
	}

	/**
	 * Восстанавливает папку из корзины на прежнее место ([internal]).
	 *
	 * Дефолт резолвится через слот plathix/folder/restore_runner (мост FolderTrashRunner::restore);
	 * так платформенный фасад не держит прямую связь с Modules\Trash (симметрия deleteFolder).
	 *
	 * @return array{restored:bool, fallbackRoot:bool, parent:int}
	 */
	public function restoreFolder(int $id, string $taxonomy): array
	{
		$runner = apply_filters('plathix/folder/restore_runner', \Plathix\Core\FolderTrashRunner::restore(...));

		/** @var array{restored:bool, fallbackRoot:bool, parent:int} $result */
		$result = $runner($id, $taxonomy);

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public function reorderTree(array $items, string $taxonomy): array
	{
		return ($this->folders_reorderer)($items, $taxonomy);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function defaultFoldersLoader(string $taxonomy): array
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());

		return array_map(
			static fn (FolderDTO $folder): array => $folder->to_array(),
			$count_service->get_all_cached($taxonomy)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function defaultFolderLoader(int $id, string $taxonomy): ?array
	{
		foreach ( $this->defaultFoldersLoader($taxonomy) as $folder ) {
			if ( (int) ($folder['id'] ?? 0) === $id ) {
				return $folder;
			}
		}

		return null;
	}

	private function defaultFolderCreator(string $name, int $parent, string $taxonomy): int|\WP_Error
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());
		$tree = new FolderTreeService($repository, $count_service);

		return $tree->create($name, $parent, $taxonomy);
	}

	private function defaultFolderRenamer(int $id, string $name, string $taxonomy): bool|\WP_Error
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());
		$tree = new FolderTreeService($repository, $count_service);

		return $tree->rename($id, $name, $taxonomy);
	}

	private function defaultFolderMover(int $id, int $parent, string $taxonomy): bool|\WP_Error
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());
		$tree = new FolderTreeService($repository, $count_service);

		return $tree->move($id, $parent, $taxonomy);
	}

	private function defaultFolderDeleter(int $id, string $taxonomy, string $onChildren): bool
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());
		$tree = new FolderTreeService($repository, $count_service);

		return $tree->delete_recursive($id, $taxonomy, $onChildren);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function defaultFoldersReorderer(array $items, string $taxonomy): array
	{
		$repository = new FolderRepository();
		$count_service = new FolderCountService($repository, Cache::make());
		$tree = new FolderTreeService($repository, $count_service);
		$reordered = [];
		$failed = [];

		foreach ( $items as $item ) {
			$id = absint($item['id'] ?? 0);
			$parentId = absint($item['parent_id'] ?? 0);
			$position = absint($item['position'] ?? 0);

			if ( $id <= 0 ) {
				$failed[] = ['id' => 0, 'message' => __('Invalid folder ID.', 'plathix')];
				continue;
			}

			$moved = $tree->move($id, $parentId, $taxonomy);
			if ( is_wp_error($moved) ) {
				$failed[] = ['id' => $id, 'code' => $moved->get_error_code(), 'message' => $moved->get_error_message()];
				continue;
			}

			$ordered = $tree->set_order($id, $position, $taxonomy);
			if ( is_wp_error($ordered) ) {
				$failed[] = ['id' => $id, 'code' => $ordered->get_error_code(), 'message' => $ordered->get_error_message()];
				continue;
			}

			$reordered[] = ['id' => $id, 'parent_id' => $parentId, 'position' => $position];
		}

		return ['reordered' => $reordered, 'failed' => $failed];
	}
}
