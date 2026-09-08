<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

use Plathix\Core\SqlSafeCast;
use Plathix\Infrastructure\Logger;
use Plathix\Modules\Import\ImportAdapterInterface;

abstract class AbstractTaxonomyImportAdapter implements ImportAdapterInterface
{

	protected const TAXONOMY = '';

	private bool $last_export_query_failed = false;

	public function hadQueryFailure(): bool {
		return $this->last_export_query_failed;
	}

	public function isAvailable(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", static::TAXONOMY )
		);

		if ( null === $count ) {
			Logger::warning( 'taxonomy_import_adapter_availability_sql_failed', [ 'taxonomy' => static::TAXONOMY ] );
		}

		return SqlSafeCast::nullSafeSqlCount( $count ) > 0;
	}

	/**
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */
	public function exportTree(): array {
		global $wpdb;

		$this->last_export_query_failed = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		if ( null === $terms ) {
			$this->last_export_query_failed = true;
			return [];
		}

		if ( ! is_array( $terms ) || $terms === [] ) {
			return [];
		}

		$term_taxonomy_ids         = array_map( static fn(array $term): int => (int) $term['term_taxonomy_id'], $terms );
		$items_by_term_taxonomy_id = self::fetchItemsByTermTaxonomyIds( $term_taxonomy_ids );

		if ( null === $items_by_term_taxonomy_id ) {
			$this->last_export_query_failed = true;
			return [];
		}

		$result = [];

		foreach ( $terms as $term ) {
			$term_taxonomy_id = (int) $term['term_taxonomy_id'];

			$entry = [
				'id'     => (int) $term['term_id'],
				'name'   => (string) $term['name'],
				'parent' => (int) $term['parent'],
				'items'  => $items_by_term_taxonomy_id[ $term_taxonomy_id ] ?? [],
			];

			if ( $this->skipTerm( $entry, $term ) ) {
				continue;
			}

			$result[] = $entry;
		}

		$result_ids = array_column( $result, 'id' );
		foreach ( $result as &$entry ) {
			if ( $entry['parent'] > 0 && ! in_array( $entry['parent'], $result_ids, true ) ) {
				$entry['parent'] = 0;
			}
		}
		unset( $entry );

		return $this->sortTree( $result );
	}

	/**
	 * @param array{id: int, name: string, parent: int, items: list<int>} $entry
	 * @param array<string, mixed>                                       $raw
	 */

	protected function skipTerm(array $entry, array $raw): bool {
		return false;
	}

	/**
	 * @param list<array{id: int, name: string, parent: int, items: list<int>}> $tree
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */

	protected function sortTree(array $tree): array {
		return $tree;
	}

	/**
	 * @param list<int> $term_taxonomy_ids
	 * @return array<int, list<int>>|null
	 */

	protected static function fetchItemsByTermTaxonomyIds(array $term_taxonomy_ids): ?array {
		if ( $term_taxonomy_ids === [] ) {
			return [];
		}

		global $wpdb;

		$id_list = implode( ',', array_map( 'intval', $term_taxonomy_ids ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows = $wpdb->get_results(
			"SELECT term_taxonomy_id, object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$id_list})",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows = SqlSafeCast::nullSafeSqlRows( $rows );
		if ( null === $rows ) {
			return null;
		}

		$items_by_term_taxonomy_id = [];
		foreach ( $rows as $row ) {
			$items_by_term_taxonomy_id[ (int) $row['term_taxonomy_id'] ][] = absint( $row['object_id'] );
		}

		return $items_by_term_taxonomy_id;
	}
}
