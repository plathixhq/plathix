<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

use Plathix\Modules\Import\ImportAdapterInterface;

/**
 * [internal] ([internal]): общий предок адаптеров, читающих папки конкурента из
 * WP-таксономии.
 *
 * До выноса четыре адаптера (FileBird, HappyFiles, WickedFolders, WPMediaFolder)
 * различались одной константой и совпадали на 89% — `diff` после нормализации имён давал
 * 11 строк из ~100, причём совпадали и докблоки, и строки `phpcs:ignore`. Одинаковое
 * обоснование, размноженное по четырём файлам, означало один невынесенный класс: три
 * подавления жили в каждой копии вместо одного набора здесь.
 *
 * Прямой SQL остаётся и это осознанно: `taxonomy_exists()` и `get_terms()` требуют, чтобы
 * плагин-конкурент зарегистрировал таксономию в текущем запросе. У деактивированного
 * конкурента данные физически лежат в БД, но core-API молча возвращает пустоту — импорт
 * из отключённого плагина сломался бы ([internal]).
 *
 * Наследник обязан объявить `TAXONOMY` и `key()`; всё остальное переопределяется только
 * при реальном отличии схемы конкурента (см. `sort_tree()`).
 */
abstract class AbstractTaxonomyImportAdapter implements ImportAdapterInterface
{
	/** Таксономия конкурента. Наследник обязан переопределить. */
	protected const TAXONOMY = '';

	/** [internal]: сбрасывается в начале каждого export_tree(), см. had_query_failure(). */
	private bool $last_export_query_failed = false;

	public function had_query_failure(): bool {
		return $this->last_export_query_failed;
	}

	public function is_available(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is a $wpdb property, taxonomy is a class constant; direct query required because the competitor may be deactivated and its taxonomy unregistered (see class docblock). %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", static::TAXONOMY )
		) > 0;
	}

	/**
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */
	public function export_tree(): array {
		global $wpdb;

		// [internal]: сбрасываем в начале — флаг не должен залипать между повторными
		// вызовами export_tree() на одном instance.
		$this->last_export_query_failed = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb properties; taxonomy is a class constant, not user input. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, tt.parent, t.name, t.slug
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				static::TAXONOMY
			),
			ARRAY_A
		);

		// [internal]: null = реальная SQL-ошибка, отличная от валидно-пустого дерева —
		// сигнализируем это вызывающей стороне (ImportManager::handle_job_import()) через
		// had_query_failure(), не только через пустой возврат, неотличимый от "нет данных".
		if ( null === $terms ) {
			$this->last_export_query_failed = true;
			return [];
		}

		if ( ! is_array( $terms ) || $terms === [] ) {
			return [];
		}

		$term_taxonomy_ids         = array_map( static fn(array $term): int => (int) $term['term_taxonomy_id'], $terms );
		$items_by_term_taxonomy_id = self::fetch_items_by_term_taxonomy_ids( $term_taxonomy_ids );

		$result = [];

		foreach ( $terms as $term ) {
			$term_taxonomy_id = (int) $term['term_taxonomy_id'];

			$entry = [
				'id'     => (int) $term['term_id'],
				'name'   => (string) $term['name'],
				'parent' => (int) $term['parent'],
				'items'  => $items_by_term_taxonomy_id[ $term_taxonomy_id ] ?? [],
			];

			if ( $this->skip_term( $entry, $term ) ) {
				continue;
			}

			$result[] = $entry;
		}

		return $this->sort_tree( $result );
	}

	/**
	 * Точка расширения: исключить служебный термин конкурента из дерева.
	 *
	 * @param array{id: int, name: string, parent: int, items: list<int>} $entry
	 * @param array<string, mixed>                                       $raw   Сырая строка выборки.
	 */
	protected function skip_term(array $entry, array $raw): bool {
		return false;
	}

	/**
	 * Точка расширения: порядок ветвей. По умолчанию — порядок выборки БД.
	 *
	 * @param list<array{id: int, name: string, parent: int, items: list<int>}> $tree
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */
	protected function sort_tree(array $tree): array {
		return $tree;
	}

	/**
	 * Один batch-запрос object_id per term_taxonomy_id вместо N+1 ([internal]). Образец
	 * query-формы — FolderCountCalculator::batch_counts: IN()-список через intval+implode,
	 * а не `prepare()`-плейсхолдеры — те не годятся для списка переменной длины.
	 *
	 * @param list<int> $term_taxonomy_ids
	 * @return array<int, list<int>> keyed by term_taxonomy_id
	 */
	protected static function fetch_items_by_term_taxonomy_ids(array $term_taxonomy_ids): array {
		if ( $term_taxonomy_ids === [] ) {
			return [];
		}

		global $wpdb;

		$id_list = implode( ',', array_map( 'intval', $term_taxonomy_ids ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every element of $id_list passed through intval(); table name is a $wpdb property. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is — $id_list itself (IN-list of values) is not an %i candidate.
		$rows = $wpdb->get_results(
			"SELECT term_taxonomy_id, object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$id_list})",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$items_by_term_taxonomy_id = [];
		foreach ( (array) $rows as $row ) {
			$items_by_term_taxonomy_id[ (int) $row['term_taxonomy_id'] ][] = absint( $row['object_id'] );
		}

		return $items_by_term_taxonomy_id;
	}
}
