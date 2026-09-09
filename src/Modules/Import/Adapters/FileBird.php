<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

use Plathix\Core\SqlSafeCast;
use Plathix\Infrastructure\TableExistenceChecker;
use Plathix\Modules\Import\ImportAdapterInterface;

class FileBird implements ImportAdapterInterface
{
	private const TABLE_FOLDERS  = 'fbv';
	private const TABLE_RELATION = 'fbv_attachment_folder';

	private bool $last_export_query_failed = false;

	public function key(): string {
		return 'filebird';
	}

	public function isAvailable(): bool {
		global $wpdb;
		return TableExistenceChecker::exists( $wpdb->prefix . self::TABLE_FOLDERS );
	}

	public function hadQueryFailure(): bool {
		return $this->last_export_query_failed;
	}

	public function exportTree(): array {

		$this->last_export_query_failed = false;

		if ( ! $this->isAvailable() ) {
			return [];
		}

		global $wpdb;

		$folders_table  = $wpdb->prefix . self::TABLE_FOLDERS;
		$relation_table = $wpdb->prefix . self::TABLE_RELATION;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$folders = $wpdb->get_results(
			"SELECT id, name, parent FROM {$folders_table} ORDER BY parent, ord, id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + hardcoded const; no user input in query. %i technically available on current min WP 7.0 but adds no security benefit here, left as-is (reviewed for %i applicability).
			ARRAY_A
		);

		if ( null === $folders ) {
			$this->last_export_query_failed = true;
			return [];
		}

		if ( ! is_array( $folders ) || $folders === [] ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads FileBird's folder-to-attachment mapping table (fbv_attachment_folder); same foreign schema, one-shot import read
		$rows = $wpdb->get_results(
			"SELECT attachment_id, folder_id FROM {$relation_table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + hardcoded const; no user input in query. %i technically available on current min WP 7.0 but adds no security benefit here, left as-is (reviewed for %i applicability).
			ARRAY_A
		);

		if ( null === $rows ) {
			$this->last_export_query_failed = true;
			return [];
		}

		$items_by_folder = [];
		foreach ( SqlSafeCast::nullSafeSqlRows( $rows ) ?? [] as $row ) {
			$items_by_folder[ (int) $row['folder_id'] ][] = (int) $row['attachment_id'];
		}

		$result = [];
		foreach ( $folders as $folder ) {
			$id     = (int) $folder['id'];
			$parent = (int) $folder['parent'];
			$result[] = [
				'id'     => $id,
				'name'   => (string) $folder['name'],
				'parent' => $parent < 0 ? 0 : $parent,
				'items'  => $items_by_folder[ $id ] ?? [],
			];
		}

		return $result;
	}
}
