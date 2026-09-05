<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Stats;

use Plathix\Core\AttachmentVisibility;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderDTO;
use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\Cache;

/**
 * Метрики папок дашборда: количество папок/файлов, распределение по типам контента,
 * максимальная глубина дерева, осиротевшие вложения, id корзины «без папки».
 *
 * Выделено из HomeDashboardData (god-object → узкие сервисы по источнику данных).
 * One-pass: единственный проход по таксономиям собирает и счётчики, и глубину —
 * раньше HomeDashboardData обходил get_all_cached дважды (основной цикл + depth_stats).
 */
class FolderStatsService
{
	private ?FolderCountService $folder_service;

	public function __construct(?FolderCountService $folder_service = null) {
		// Ленивая инициализация: не дёргаем Cache::make() в конструкторе, иначе
		// `new HomeDashboardData()` потянул бы object-cache даже там, где папки не нужны.
		$this->folder_service = $folder_service;
	}

	private function folder_service(): FolderCountService {
		return $this->folder_service
			??= new FolderCountService( new FolderRepository(), Cache::make() );
	}

	/**
	 * @param string[] $post_types
	 * @return array{
	 *   total_folders:int, total_files:int,
	 *   distribution:array<int,array{label:string,folders:int,files:int}>,
	 *   max_depth:int, orphaned_files:int, uncategorized_folder_id:int
	 * }
	 */
	public function collect(array $post_types): array {
		// Кэш на час (статистика не реалтайм). Ключ зависит от набора post_types —
		// разные наборы дают разные метрики. Накрывает orphaned-JOIN и one-pass PHP.
		$sorted    = $post_types;
		sort( $sorted );
		$cache     = Cache::make();
		$cache_key = $cache->versioned_key( Cache::DASHBOARD_STATS_GROUP, 'folder_stats_' . md5( implode( ',', $sorted ) ) );
		$cached    = $cache->get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['total_folders'] ) ) {
			/** @var array{total_folders: int, total_files: int, distribution: array<int, array{label: string, folders: int, files: int}>, max_depth: int, orphaned_files: int, uncategorized_folder_id: int} $cached */
			return $cached;
		}

		$total_folders = 0;
		$total_files   = 0;
		$distribution  = [];
		$parent_map    = [];

		// Один проход по таксономиям: и счётчики, и parent_map для глубины.
		foreach ( $post_types as $pt ) {
			$taxonomy = TaxonomyResolver::fromPostType( $pt );
			$folders  = $this->folder_service()->get_all_cached( $taxonomy );

			// Системные папки (All-files / Uncategorized / Trash) в FolderDTO помечены
			// isProtected=true (см. FolderCountService::get_all_cached) — отсеиваем по флагу,
			// а не по slug: свойства slug у FolderDTO нет, прежнее $f->slug давало null и
			// системные папки ошибочно считались пользовательскими.
			$real_folders = array_filter( $folders, static fn(FolderDTO $f): bool => ! $f->isProtected );
			$pt_folders   = count( $real_folders );
			$total_folders += $pt_folders;

			$pt_total = $this->count_published_posts( $pt );
			$total_files += $pt_total;

			if ( $pt_folders > 0 ) {
				$distribution[] = [
					'label'   => $this->post_type_label( $pt ),
					'folders' => $pt_folders,
					'files'   => $pt_total,
				];
			}

			foreach ( $folders as $f ) {
				// FolderDTO: id (int|string), parentId (int). Прежние $f->term_id/$f->parent
				// не существуют в DTO — давали null, из-за чего parent_map вырождался в [0=>0]
				// и max_depth всегда равнялся 0 при любой реальной вложенности.
				$parent_map[ (int) $f->id ] = $f->parentId;
			}
		}

		$result = [
			'total_folders'           => $total_folders,
			'total_files'             => $total_files,
			'distribution'            => $distribution,
			'max_depth'               => $this->max_depth( $parent_map ),
			'orphaned_files'          => $this->count_orphaned_attachments(),
			'uncategorized_folder_id' => ( new FolderRepository() )->get_uncategorized_term_id( PLATHIX_TAXONOMY ),
		];
		$cache->set( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/** @param array<int,int> $parent_map term_id => parent_id */
	private function max_depth(array $parent_map): int {
		$max_depth = 0;
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
			if ( $depth > $max_depth ) {
				$max_depth = $depth;
			}
		}
		return $max_depth;
	}

	private function count_published_posts(string $post_type): int {
		if ( 'attachment' === $post_type ) {
			// User-facing счётчик dashboard («N файлов» / «Типы контента»): единый visibility-aware
			// подсчёт ([internal], техдолг #121). Storage-семантика — inherit+private (private включён,
			// [internal]); generator-hidden Elementor-скриншоты исключаются предикатом AttachmentVisibility.
			return AttachmentVisibility::count_visible( [ 'inherit', 'private' ] );
		}
		$counts = wp_count_posts( $post_type );
		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * «Потерянные» = attachment вне пользовательских папок = ровно содержимое системной папки
	 * «Несортированные» (Uncategorized). [internal]: раньше виджет считал СВОИМ SQL
	 * (post_status='inherit'), а папка «Несортированные» — своим (post_status IN 'inherit','private'),
	 * и на private-вложениях числа расходились (тот же класс дрейфа, что 324 vs 319 в #114).
	 *
	 * Делегируем в ЕДИНЫЙ источник — FolderCountService::get_count(uncategorized_id), который для
	 * uncategorized-папки вызывает get_uncategorized_items_count. Так число виджета «Потерянные»
	 * тождественно числу папки «Несортированные» by construction (включая private + AttachmentVisibility).
	 * Двойной кэш (снапшот collect на час + собственный кэш get_count) не хуже прежнего — collect и до
	 * этого кешировал снапшот; оба инвалидируются при изменении папок.
	 */
	private function count_orphaned_attachments(): int {
		$uncategorized_id = ( new FolderRepository() )->get_uncategorized_term_id( PLATHIX_TAXONOMY );

		return $this->folder_service()->get_count( $uncategorized_id, PLATHIX_TAXONOMY );
	}

	private function post_type_label(string $pt): string {
		$obj = get_post_type_object( $pt );
		return $obj?->labels->name ?? $pt;
	}
}
