<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Infrastructure\Cache;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;

/**
 * CLI-facing фасад «обслуживающих» folder-операций Free-ядра.
 *
 * Отделён от публичного `FoldersApi` намеренно (WP Architecture Skeptic, 2026-07-02,
 * [internal]): здесь живёт raw-репозиторная и cache-lifecycle семантика,
 * которая внешнему CRUD-контракту (REST/admin) не нужна и не должна течь в него
 * (`WP_Term`-форма из `get_all`, имена кэш-групп из `bump_version`). Собрана в одну
 * стабильную границу, чтобы потребитель вне ядра (CLI-модуль) зависел от контракта Free,
 * а не от internal-классов `Core\*` / `Infrastructure\Cache` напрямую.
 *
 * Имя — по семантике (maintenance), не по потребителю: если admin-tools захотят
 * «пересобрать порядок / сбросить кэш», фасад подойдёт без переименования.
 *
 * @api
 */
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
	 * Все термы-папки таксономии в raw-форме (`WP_Term[]`).
	 *
	 * НЕ путать с `FoldersApi::getFolders` — та отдаёт причёсанный DTO-массив для
	 * внешнего мира; здесь raw для низкоуровневых CLI-операций (stats, audit-репар).
	 *
	 * @api
	 * @return array<int, \WP_Term>
	 */
	public function getRawFolders(string $taxonomy): array
	{
		return ($this->raw_folders_loader)($taxonomy);
	}

	/**
	 * Является ли папка системной «Uncategorized» в данной таксономии.
	 *
	 * @api
	 */
	public function isUncategorized(int $folderId, string $taxonomy): bool
	{
		return (bool) ($this->uncategorized_checker)($folderId, $taxonomy);
	}

	/**
	 * Пересобрать порядок папок внутри ветки (после реорганизации структуры).
	 *
	 * @api
	 */
	public function normalizeOrder(string $taxonomy, int $parentId = 0): void
	{
		($this->order_normalizer)($taxonomy, $parentId);
	}

	/**
	 * Сбросить кэши структуры папок (все таксономии) + bump версий tree/gallery.
	 *
	 * Инкапсулирует cache-lifecycle: потребитель не знает про имена кэш-групп
	 * (`folders_tree`/`gallery_items`) и не держит ссылку на `Infrastructure\Cache`.
	 *
	 * @api
	 */
	public function flushCaches(): void
	{
		($this->cache_flusher)();
	}

	/**
	 * Точечно сбросить кэш счётчиков одной таксономии (напр. после смены цвета папки).
	 *
	 * @api
	 */
	public function invalidateTaxonomy(string $taxonomy): void
	{
		($this->taxonomy_invalidator)($taxonomy);
	}

	/**
	 * @return array<int, \WP_Term>
	 */
	private function defaultRawFoldersLoader(string $taxonomy): array
	{
		return (new FolderRepository())->get_all($taxonomy);
	}

	private function defaultUncategorizedChecker(int $folderId, string $taxonomy): bool
	{
		return (new FolderRepository())->is_uncategorized_folder($folderId, $taxonomy);
	}

	private function defaultOrderNormalizer(string $taxonomy, int $parentId): void
	{
		$repository = new FolderRepository();
		$tree = new FolderTreeService($repository, new FolderCountService($repository, Cache::make()));
		$tree->normalize_order($taxonomy, $parentId);
	}

	private function defaultCacheFlusher(): void
	{
		$cache = Cache::make();
		$count_service = new FolderCountService(new FolderRepository(), $cache);
		$count_service->invalidate_all_taxonomies();
		$cache->bump_version('folders_tree');
		$cache->bump_version('gallery_items');
	}

	private function defaultTaxonomyInvalidator(string $taxonomy): void
	{
		$repository = new FolderRepository();
		(new FolderCountService($repository, Cache::make()))->invalidate($taxonomy);
	}
}
