<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Logger;

final class FolderSizeBatchCalculator
{

	private const PAGE_SIZE = 500;

	public function batchBytesRecursive(int $folder_id, string $taxonomy): int {
		$descendant_terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'child_of'   => $folder_id,
				'fields'     => 'ids',
			]
		);

		if ( is_wp_error( $descendant_terms ) ) {
			Logger::warning( 'folder_size_batch_calculator_descendant_terms_failed', [ 'folder_id' => $folder_id, 'taxonomy' => $taxonomy ] );
		}

		$subtree_ids = is_wp_error( $descendant_terms ) || ! is_array( $descendant_terms )
			? []
			: array_map( 'intval', $descendant_terms );

		if ( empty( $subtree_ids ) ) {
			return 0;
		}

		$totals = $this->batchBytes( $subtree_ids, $taxonomy );

		return null === $totals ? 0 : array_sum( $totals );
	}

	/**
	 * @param array<int> $term_ids
	 * @return array<int, int>|null
	 */

	public function batchBytes(array $term_ids, string $taxonomy): ?array {
		global $wpdb;

		if ( empty( $term_ids ) ) {
			return [];
		}

		try {
			$safe_ids     = array_map( 'intval', $term_ids );
			$id_list      = implode( ',', $safe_ids );
			$taxonomy_esc = esc_sql( $taxonomy );

			$totals = [];
			$cursor = 0;

			do {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is intval'd, $taxonomy_esc is esc_sql()'d, $wpdb->* are core table names, cursor/LIMIT go through prepare()
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT tr.object_id, tt.term_id, pm.meta_value AS attachment_metadata
						   FROM {$wpdb->term_relationships} tr
						   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
						   JOIN {$wpdb->postmeta} pm       ON pm.post_id = tr.object_id AND pm.meta_key = '_wp_attachment_metadata'
						  WHERE tt.term_id IN ({$id_list})
						    AND tt.taxonomy = '{$taxonomy_esc}'
						    AND tr.object_id > %d
						  ORDER BY tr.object_id ASC
						  LIMIT %d",
						$cursor,
						self::PAGE_SIZE
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( null === $rows ) {
					return null;
				}

				$row_count = 0;

				foreach ( (array) $rows as $row ) {
					++$row_count;

					$cursor   = (int) $row->object_id;
					$term_id  = (int) $row->term_id;

					$metadata = maybe_unserialize( (string) $row->attachment_metadata );

					if ( ! is_array( $metadata ) || ! isset( $metadata['filesize'] ) || ! is_numeric( $metadata['filesize'] ) ) {
						continue;
					}

					$totals[ $term_id ] = ( $totals[ $term_id ] ?? 0 ) + (int) $metadata['filesize'];
				}
			} while ( self::PAGE_SIZE === $row_count );

			return $totals;
		} catch ( \Throwable $e ) {
			Logger::error( __METHOD__ . ': batchBytes failed.', [], $e );

			return null;
		}
	}
}
