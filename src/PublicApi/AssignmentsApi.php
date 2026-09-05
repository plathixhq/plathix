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
	/** @var \Closure(int, string, int, int, array<int, string>): array<string, mixed> */
	private \Closure $items_loader;
	/** @var \Closure(array<int, int>, string): array<string, mixed> */
	private \Closure $media_trash;
	/** @var \Closure(array<int, int>, int, string): array<string, mixed> */
	private \Closure $media_restore;
	/** @var \Closure(array<int, int>, int, string): array<string, mixed> */
	private \Closure $items_assigner;

	public function __construct(
		?callable $items_mover = null,
		?callable $items_loader = null,
		?callable $media_trash = null,
		?callable $media_restore = null,
		?callable $items_assigner = null
	) {
		$this->items_mover = \Closure::fromCallable($items_mover ?? [$this, 'defaultItemsMover']);
		$this->items_loader = \Closure::fromCallable($items_loader ?? [$this, 'defaultItemsLoader']);
		$this->media_trash = \Closure::fromCallable($media_trash ?? [$this, 'defaultMediaTrash']);
		$this->media_restore = \Closure::fromCallable($media_restore ?? [$this, 'defaultMediaRestore']);
		$this->items_assigner = \Closure::fromCallable($items_assigner ?? [$this, 'defaultItemsAssigner']);
	}

	/**
	 * Первичное присвоение папки — БЕЗ trash-aware маршрутизации (в отличие от
	 * moveItems()/MediaMoveOrchestrator, спроектированной под attachment/media
	 * trash-lifecycle). Для non-attachment типов (страницы/посты), у которых нет
	 * trash-in-folder сценария — WP штатно переводит post_status через
	 * wp_trash_post(), это другой lifecycle ([internal]).
	 *
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
	 * @param array<int, string> $fields
	 * @return array<string, mixed>
	 */
	public function getFolderItems(int $folderId, string $postType = 'attachment', int $page = 1, int $perPage = 50, array $fields = []): array
	{
		return ($this->items_loader)($folderId, TaxonomyResolver::fromPostType($postType), $page, $perPage, $fields);
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
	 * Trash-aware маршрутизация ([internal]/#235) через общий источник истины —
	 * раньше этот closure вызывал FolderAssignmentService::move_items_bulk() напрямую,
	 * обходя её ([internal], находка B — тот же баг-класс через WP-CLI/PublicApi канал).
	 *
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
	private function defaultItemsAssigner(array $ids, int $folderId, string $taxonomy): array
	{
		$repository = new FolderRepository();
		$cache = Cache::make();
		$count_service = new FolderCountService($repository, $cache);
		$assignment = new FolderAssignmentService($repository, $count_service, $cache);

		return $assignment->set_items($ids, $folderId, $taxonomy);
	}

	/**
	 * @param array<int, string> $fields
	 * @return array<string, mixed>
	 */
	private function defaultItemsLoader(int $folderId, string $taxonomy, int $page, int $perPage, array $fields): array
	{
		$objectIds = get_objects_in_term($folderId, $taxonomy);
		if ( is_wp_error($objectIds) ) {
			return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
		}

		$allIds = array_values(array_unique(array_filter(array_map('absint', (array) $objectIds))));
		$total = count($allIds);
		$pageIds = array_slice($allIds, max(0, ($page - 1) * $perPage), $perPage);

		if ( $pageIds === [] ) {
			return ['items' => [], 'total' => $total, 'page' => $page, 'perPage' => $perPage];
		}

		$posts = get_posts(
			[
				'post_type' => 'any',
				'post_status' => 'any',
				'post__in' => $pageIds,
				'orderby' => 'post__in',
				'posts_per_page' => count($pageIds),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$items = [];
		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			/** @var \WP_Post&object{ID:int,post_title:string,post_date_gmt:string,post_author:string,post_parent:int,post_name:string,post_mime_type:string} $post -- phpstan-wordpress stub omits declared WP_Post properties */
			$data = [
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'status' => (string) $post->post_status,
				'type' => (string) $post->post_type,
				'date' => (string) $post->post_date_gmt,
				'author' => (int) $post->post_author,
				'parent' => (int) $post->post_parent,
				'slug' => (string) $post->post_name,
				'mime' => (string) $post->post_mime_type,
			];

			if ( $fields !== [] ) {
				$data = array_intersect_key($data, array_fill_keys($fields, true));
				if ( ! array_key_exists('id', $data) ) {
					$data['id'] = (int) $post->ID;
				}
			}

			$items[] = $data;
		}

		return [
			'items' => $items,
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
		];
	}

	/**
	 * Дефолтный trash-runner резолвится через платформенный слот `plathix/media/trash_runner`
	 * ([internal]): фасад не держит прямой ссылки на класс действий, чтобы его
	 * перенос в Modules\Trash не создавал reverse-coupling платформа→модуль (ось B2). Без
	 * подписчика слот отдаёт платформенный дефолт MediaTrashRunner.
	 *
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
	 * Дефолтный restore-runner — слот `plathix/media/restore_runner` (см. defaultMediaTrash).
	 *
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
