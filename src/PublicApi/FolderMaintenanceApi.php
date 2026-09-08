<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Infrastructure\Cache;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;

final class FolderMaintenanceApi
{
	/** @var \Closure(string): array<int, \WP_Term> */
	private \Closure $raw_folders_loader;
	/** @var \Closure(int, string): bool */
	private \Closure $uncategorized_checker;
	/** @var \Closure(string, int): void */
	private \Closure $order_normalizer;
	/** @var \Closure(): void */
	private \Closure $cache_flusher;
	/** @var \Closure(string): void */
	private \Closure $taxonomy_invalidator;

	public function __construct(
		?callable $raw_folders_loader = null,
		?callable $uncategorized_checker = null,
		?callable $order_normalizer = null,
		?callable $cache_flusher = null,
		?callable $taxonomy_invalidator = null
	) {
		$this->raw_folders_loader = \Closure::fromCallable($raw_folders_loader ?? [$this, 'defaultRawFoldersLoader']);
		$this->uncategorized_checker = \Closure::fromCallable($uncategorized_checker ?? [$this, 'defaultUncategorizedChecker']);
		$this->order_normalizer = \Closure::fromCallable($order_normalizer ?? [$this, 'defaultOrderNormalizer']);
		$this->cache_flusher = \Closure::fromCallable($cache_flusher ?? [$this, 'defaultCacheFlusher']);
		$this->taxonomy_invalidator = \Closure::fromCallable($taxonomy_invalidator ?? [$this, 'defaultTaxonomyInvalidator']);
	}

	/**
	 * @return array<int, \WP_Term>
	 */

	public function getRawFolders(string $taxonomy): array
	{
		return ($this->raw_folders_loader)($taxonomy);
	}

	public function isUncategorized(int $folderId, string $taxonomy): bool
	{
		return (bool) ($this->uncategorized_checker)($folderId, $taxonomy);
	}

	public function normalizeOrder(string $taxonomy, int $parentId = 0): void
	{
		($this->order_normalizer)($taxonomy, $parentId);
	}

	public function flushCaches(): void
	{
		($this->cache_flusher)();
	}

	public function invalidateTaxonomy(string $taxonomy): void
	{
		($this->taxonomy_invalidator)($taxonomy);
	}

	/**
	 * @return array<int, \WP_Term>
	 */
	private function defaultRawFoldersLoader(string $taxonomy): array
	{
		return (new FolderRepository())->getAll($taxonomy);
	}

	private function defaultUncategorizedChecker(int $folderId, string $taxonomy): bool
	{
		return (new FolderRepository())->isUncategorizedFolder($folderId, $taxonomy);
	}

	private function defaultOrderNormalizer(string $taxonomy, int $parentId): void
	{
		$repository = new FolderRepository();
		$tree = new FolderTreeService($repository, new FolderCountService($repository, Cache::make()));
		$tree->normalizeOrder($taxonomy, $parentId);
	}

	private function defaultCacheFlusher(): void
	{
		$cache = Cache::make();
		$countService = new FolderCountService(new FolderRepository(), $cache);
		$countService->invalidateAllTaxonomies();
		$cache->bumpVersion('folders_tree');
		$cache->bumpVersion('gallery_items');
	}

	private function defaultTaxonomyInvalidator(string $taxonomy): void
	{
		$repository = new FolderRepository();
		(new FolderCountService($repository, Cache::make()))->invalidate($taxonomy);
	}
}
