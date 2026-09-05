<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

use Plathix\Infrastructure\TableExistenceChecker;
use Plathix\Modules\Import\ImportAdapterInterface;

class RealMediaLib implements ImportAdapterInterface
{
	private const TABLE_FOLDERS = 'realmedialibrary';
	private const TABLE_POSTS   = 'realmedialibrary_posts';

	/** [internal]: сбрасывается в начале каждого export_tree(), см. had_query_failure(). */
	private bool $last_export_query_failed = false;

	public function key(): string {
		return 'realmedialib';
	}

	public function is_available(): bool {
		global $wpdb;
		return TableExistenceChecker::exists( $wpdb->prefix . self::TABLE_FOLDERS );
	}

	public function had_query_failure(): bool {
		return $this->last_export_query_failed;
	}

	public function export_tree(): array {
		// [internal]: сбрасываем в начале — флаг не должен залипать между повторными
		// вызовами export_tree() на одном instance.
		$this->last_export_query_failed = false;

		if ( ! $this->is_available() ) {
			return [];
		}

		global $wpdb;

		$folders_table = $wpdb->prefix . self::TABLE_FOLDERS;
		$posts_table   = $wpdb->prefix . self::TABLE_POSTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads Real Media Library's folder table directly; that plugin stores folders in its own schema, not in a WP taxonomy, so get_terms() cannot reach the data
		$folders = $wpdb->get_results(
			"SELECT id, name, parent FROM {$folders_table} ORDER BY parent, id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + hardcoded const; no user input in query. %i technically available on current min WP 7.0 but adds no security benefit here, left as-is (reviewed for %i applicability).
			ARRAY_A
		);

		// [internal]: null = реальная SQL-ошибка, отличная от валидно-пустого дерева —
		// сигнализируем это вызывающей стороне через had_query_failure().
		if ( null === $folders ) {
			$this->last_export_query_failed = true;
			return [];
		}

		if ( ! is_array( $folders ) || $folders === [] ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads Real Media Library's folder-to-attachment mapping table; same foreign schema, one-shot import read
		$rows = $wpdb->get_results(
			"SELECT fid, attachment FROM {$posts_table} WHERE isShortcut = 0 ORDER BY fid", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + hardcoded const; no user input in query. %i technically available on current min WP 7.0 but adds no security benefit here, left as-is (reviewed for %i applicability).
			ARRAY_A
		);

		$items_by_folder = [];
		foreach ( (array) $rows as $row ) {
			$items_by_folder[ (int) $row['fid'] ][] = (int) $row['attachment'];
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
