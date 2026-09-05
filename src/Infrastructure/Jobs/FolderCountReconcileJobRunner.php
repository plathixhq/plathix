<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Core\FolderCountCalculator;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\TrashFolder;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobLockService;

/**
 * Reconcile-самопочинка recursive-счётчиков папок ([internal], нога 2, форма R1).
 *
 * Termmeta `_plathix_folder_count_recursive` — инкрементальное состояние без TTL: любой
 * пропущенный/задвоенный ±1 (гонка, сторонний прямой SQL, известные ограничения
 * событийной модели — append/wp_remove_object_terms) жил бы вечно. Этот cron-прогон
 * пересверяет значения с живой SQL-истиной (FolderCountCalculator::batch_counts) и
 * переписывает ТОЛЬКО дрейфанувшие. Первый прогон закрывает и дрейф, накопленный до
 * пакета (бэкфилл, отложенный FCTRUTH #692).
 *
 * Семантика — eventual (концессия concurrency-скептика пакета): окно «SQL-мутация уже
 * видна, termmeta-дельта конкурента ещё не применена» не закрывается никаким локом —
 * такой конкурентный прогон может затереть/задвоить одну дельту, и это чинится
 * СЛЕДУЮЩИМ прогоном. Точность одного прохода недостижима by design; корректность =
 * периодическое затухание дрейфа. Поэтому форма — гарантированно повторяющийся
 * daily-cron (Action Scheduler), не одноразовый лениво-триггерный чек.
 *
 * Perf-модель (концессия perf-скептика, R1; R2/R3 отклонены — см. пакет): вся тяжесть
 * вне пользовательского запроса; термы чанками по CHUNK_SIZE (один GROUP-BY JOIN на
 * чанк вместо COUNT-на-папку), bottom-up агрегация в PHP, запись только дрейфа
 * (обычный прогон без дрейфа = 0 UPDATE).
 */
final class FolderCountReconcileJobRunner
{
	public const CHUNK_SIZE = 500;

	/**
	 * Маркер последнего успешного прогона (observability; ставится ПОСЛЕ записей —
	 * упавший посередине прогон маркер не двигает). Префикс `plathix_` обязателен:
	 * DataWiper::wipe_options() сносит опции LIKE 'plathix_%' при uninstall/wipe.
	 */
	public const LAST_RUN_OPTION = 'plathix_fc_reconcile_last_run';

	private JobLockService $lock_service;
	private FolderCountCalculator $calculator;
	private ?FolderCountService $count_service;

	public function __construct(?JobLockService $lock_service = null, ?FolderCountService $count_service = null)
	{
		$this->lock_service  = $lock_service ?? new JobLockService();
		$this->calculator    = new FolderCountCalculator();
		$this->count_service = $count_service;
	}

	private function count_service(): FolderCountService
	{
		return $this->count_service ??= new FolderCountService( new FolderRepository(), Cache::make() );
	}

	/** @param array<string, mixed> $args */
	public function run(array $args, callable $run_in_blog_context): void
	{
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$run_in_blog_context(
			$blog_id,
			function (): void {
				$this->reconcile_all();
			}
		);
	}

	/**
	 * Прогон по всем папочным таксономиям блога под execution-lock (reconcile↔reconcile
	 * mutual exclusion; GET_LOCK(,0) — конкурентный прогон честно и молча отступает,
	 * session-scoped лок при фатале держателя освобождается закрытием MySQL-сессии —
	 * модель отказа задокументирована в JobLockService).
	 */
	public function reconcile_all(): void
	{
		$fingerprint = 'fc_reconcile_' . get_current_blog_id();
		$lock        = $this->lock_service->acquire_execution( $fingerprint );
		if ( ! ( $lock['acquired'] ?? false ) ) {
			return;
		}

		try {
			$taxonomies = array_values(
				array_filter(
					get_taxonomies(),
					static fn (string $taxonomy): bool => PLATHIX_TAXONOMY === $taxonomy || str_starts_with( $taxonomy, PLATHIX_TAX_PREFIX )
				)
			);

			$completed = true;
			foreach ( $taxonomies as $taxonomy ) {
				if ( ! $this->reconcile_taxonomy( (string) $taxonomy ) ) {
					$completed = false;
				}
			}

			// Маркер — ПОСЛЕ записей и только за полный прогон: упавшая/недописанная
			// таксономия не отчитывается как выверенная.
			if ( $completed ) {
				update_option( self::LAST_RUN_OPTION, time(), false );
			}
		} finally {
			$this->lock_service->release_execution( $fingerprint );
		}
	}

	/**
	 * Пересверка одной таксономии. Возвращает false, если запись не выполнена
	 * (SQL-провал batch_counts на любом чанке): recursive-значение любого предка
	 * зависит от direct-значений потомков — писать «дрейф», посчитанный по неполным
	 * данным, значило бы въехать в ровно тот класс «SQL-fail записан как факт»,
	 * который закрыл #692/#798. Провал чанка = таксономия в этом прогоне не трогается,
	 * следующий прогон попробует снова (eventual).
	 */
	private function reconcile_taxonomy(string $taxonomy): bool
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
		$uncategorized_id = $repository->get_uncategorized_term_id( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );

		/** @var array<int, int> $parents term_id => parent_id (спец-термы исключены: они не носят recursive-termmeta) */
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

		// Direct-счёт чанками — SQL-истина того же калькулятора, что кормит cold-seed.
		$direct = [];
		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
			$counts = $this->calculator->batch_counts( $chunk, $taxonomy );
			if ( null === $counts ) {
				return false;
			}
			foreach ( $chunk as $id ) {
				$direct[ $id ] = $counts[ $id ] ?? 0;
			}
		}

		// Bottom-up: recursive(id) = direct(id) + Σ recursive(children). Children-map из
		// уже полученного id=>parent (0 дополнительных запросов).
		$children = [];
		foreach ( $parents as $id => $parent_id ) {
			if ( $parent_id > 0 && isset( $parents[ $parent_id ] ) ) {
				$children[ $parent_id ][] = $id;
			}
		}
		$recursive = [];
		$resolve   = function (int $id) use (&$resolve, &$recursive, $children, $direct): int {
			if ( isset( $recursive[ $id ] ) ) {
				return $recursive[ $id ];
			}
			$sum = $direct[ $id ] ?? 0;
			foreach ( $children[ $id ] ?? [] as $child_id ) {
				$sum += $resolve( $child_id );
			}
			return $recursive[ $id ] = $sum;
		};
		foreach ( $ids as $id ) {
			$resolve( $id );
		}

		// Батч-прогрев termmeta и запись только дрейфа.
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
			$this->count_service()->overwrite_recursive_count( $id, $recursive[ $id ] );
			++$writes;
		}

		// Порядок строго data -> bump (иначе окно класса #774: читатель успевает
		// закэшировать старые числа под новой версией на TTL).
		if ( $writes > 0 ) {
			$this->count_service()->invalidate( $taxonomy );
		}

		return true;
	}
}
