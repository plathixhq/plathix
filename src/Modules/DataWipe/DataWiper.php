<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\Core\SqlSafeCast;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Logger;
use Plathix\PublicApi\TrashApi;

final class DataWiper
{
	/**
	 * @param int $blog_id
	 */

	public function wipe(int $blog_id): void
	{
		$this->wipeTerms();
		$this->dropCustomTables();
		$this->wipeTrashTimePostmeta();
		$this->wipeOptions();
		$this->wipeUserMeta($blog_id);
		$this->clearActionSchedulerGroups($blog_id);
		$this->clearCronHooks();
		$this->wipeTempDirs();
		$this->wipePresetsDirectory();
	}

	private function wipeTerms(): void
	{
		foreach ( $this->taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {

				$this->deleteOrphanTermsForTaxonomy( $taxonomy );
				continue;
			}

			$term_ids = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);

			if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				$deleted = wp_delete_term( (int) $term_id, $taxonomy );

				if ( is_wp_error( $deleted ) || false === $deleted ) {
					Logger::warning( 'data_wiper_delete_term_failed', [ 'term_id' => (int) $term_id, 'taxonomy' => $taxonomy ] );
				}
			}
		}
	}

	private function deleteOrphanTermsForTaxonomy(string $taxonomy): void
	{
		global $wpdb;

		$tt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot cleanup of unregistered-taxonomy terms during full data wipe; caching irrelevant for teardown DELETEs
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);

		if ( null === $tt_ids ) {
			Logger::warning( 'data_wiper_orphan_terms_sql_failed', [ 'taxonomy' => $taxonomy ] );
		}

		if ( ! SqlSafeCast::nullSafeSqlRows( $tt_ids ) ) {
			return;
		}

		$term_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- teardown: collects term ids of the plugin's own taxonomy for deletion; uninstall-only, nothing to cache
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);

		$tt_in = implode( ',', array_map( 'intval', $tt_ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($tt_in)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tt_in is intval-mapped ids, not user input
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- teardown: deletes the plugin's own taxonomy rows; taxonomy bound via %s, uninstall-only write, caching N/A

		if ( $term_ids ) {
			$term_in = implode( ',', array_map( 'intval', $term_ids ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($term_in)" );
			$wpdb->query(
				"DELETE t FROM {$wpdb->terms} t
				 LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				 WHERE t.term_id IN ($term_in) AND tt.term_id IS NULL"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	private function dropCustomTables(): void
	{
		global $wpdb;

		$presets_table = $wpdb->prefix . 'plathix_presets';
		$wpdb->query( "DROP TABLE IF EXISTS {$presets_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix + literal, not user input; %i (WP 6.2+) is available on our min WP 7.0 but adds no security benefit for a $wpdb->prefix-based literal; see class docblock
	}

	private function wipeTrashTimePostmeta(): void
	{
		global $wpdb;

		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => ( new TrashApi() )->trashTimeMetaKey() ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- teardown: bulk delete of the plugin's own postmeta rows by indexed meta_key; uninstall-only write, caching N/A
	}

	private function wipeOptions(): void
	{
		global $wpdb;

		$like_plathix = $wpdb->esc_like( 'plathix_' ) . '%';

		$license_options = [
			\Plathix\Edition::STATUS_OPTION,
			\Plathix\Edition::KEY_OPTION,
			\Plathix\Edition::EXPIRES_OPTION,
			\Plathix\Edition::LAST_CHECK_OPTION,
			'plathix_license_instance',
			'plathix_license_grace_since',
		];
		$license_error_transient = 'plathix_license_last_error';

		$license_placeholders = implode( ', ', array_fill( 0, count( $license_options ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- teardown: bulk delete of the plugin's own rows; uninstall-only write, caching N/A. {$license_placeholders} is a fixed-count '%s, %s, ...' skeleton built from the constant $license_options array above (not user input) — the actual values still go through $wpdb->prepare()'s %s placeholders via the variadic spread below, only the placeholder COUNT is interpolated; phpcs statically sees one literal %s before this comment's skeleton is interpolated and miscounts against the runtime-sized argument list.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE ( option_name LIKE %s AND option_name NOT IN ({$license_placeholders}) )
				    OR ( option_name LIKE %s AND option_name != %s )
				    OR ( option_name LIKE %s AND option_name != %s )",
				...array_merge(
					[ $like_plathix ],
					$license_options,
					[
						'_transient_' . $like_plathix,
						'_transient_' . $license_error_transient,
						'_transient_timeout_' . $like_plathix,
						'_transient_timeout_' . $license_error_transient,
					]
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * @return array<string, bool>
	 */

	private function suffixedUserMetaFamilies(): array
	{
		return SuffixedUserMetaFamilies::FAMILIES;
	}

	private function wipeUserMeta(int $blog_id): void
	{
		global $wpdb;

		$user_ids = get_users( [ 'blog_id' => $blog_id, 'fields' => 'ID' ] );
		if ( ! $user_ids ) {
			return;
		}

		$user_id_in = implode( ',', array_map( 'intval', $user_ids ) );
		$blogSuffix = is_multisite() ? '_' . $blog_id : '';

		$suffix_conditions = [];
		$suffix_params      = [];
		$excluded_bases     = [];

		foreach ( $this->suffixedUserMetaFamilies() as $base => $allows_post_type ) {
			$pattern              = $allows_post_type
				? $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( $blogSuffix )
				: $wpdb->esc_like( $base . $blogSuffix );
			$suffix_conditions[]  = 'meta_key LIKE %s';
			$suffix_params[]      = $pattern;
			$excluded_bases[]     = $wpdb->esc_like( $base ) . '%';
		}

		$other_condition = 'meta_key LIKE %s';
		foreach ( $excluded_bases as $excluded_base ) {
			$other_condition .= ' AND meta_key NOT LIKE %s';
		}

		$where  = '( ' . implode( ' OR ', $suffix_conditions ) . ' ) OR ( ' . $other_condition . ' )';
		$params = array_merge( $suffix_params, [ $wpdb->esc_like( 'plathix_' ) . '%' ], $excluded_bases );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $user_id_in is intval-mapped ids from get_users(), not user input; meta_key patterns bound via prepare() below. {$where} interpolates a variable-length '%s'/'meta_key LIKE %s' chain built above from $excluded_bases (loop count known only at runtime) with $params sized to match — phpcs's static placeholder count can't follow that loop and misreports the query as unfinished.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta}
				 WHERE ( {$where} )
				   AND user_id IN ($user_id_in)",
				...$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	private function clearActionSchedulerGroups(int $blog_id): void
	{
		global $wpdb;

		foreach ( [ JobDispatcher::groupForBlog( $blog_id ) ] as $group_slug ) {
			$groups_tbl  = $wpdb->prefix . 'actionscheduler_groups';
			$actions_tbl = $wpdb->prefix . 'actionscheduler_actions';
			$logs_tbl    = $wpdb->prefix . 'actionscheduler_logs';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$exists = (
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups_tbl ) ) === $groups_tbl &&
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_tbl ) ) === $actions_tbl &&
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) ) === $logs_tbl
			);

			if ( ! $exists ) {
				continue;
			}

			$raw_group_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT group_id FROM {$groups_tbl} WHERE slug = %s", $group_slug )
			);

			if ( null === $raw_group_id ) {
				Logger::warning( 'data_wiper_action_scheduler_group_lookup_sql_failed', [ 'group_slug' => $group_slug ] );
			}

			$group_id = SqlSafeCast::nullSafeSqlCount( $raw_group_id ) ?? 0;

			if ( $group_id <= 0 ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE l FROM {$logs_tbl} l INNER JOIN {$actions_tbl} a ON l.action_id = a.action_id WHERE a.group_id = %d",
					$group_id
				)
			);
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions_tbl} WHERE group_id = %d", $group_id ) );
			$wpdb->delete( $groups_tbl, [ 'group_id' => $group_id ] );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}

	private function clearCronHooks(): void
	{
		$hooks = [
			'plathix_cleanup_temp',
			'plathix_job_cleanup_temp',
			'plathix_job_import',
			'plathix_job_reorder',
			'plathix_job_orphan_cleanup',

			'plathix_job_import_checkpoint_cleanup',
		];

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	private function wipeTempDirs(): void
	{
		foreach ( $this->tempDirs() as $dir ) {
			$this->deleteDirContents( $dir );
		}
	}

	private function wipePresetsDirectory(): void
	{
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return;
		}

		$this->deleteDirContents( trailingslashit( (string) $upload['basedir'] ) . 'plathix/presets' );
	}

	/**
	 * @return list<string>
	 */

	private function taxonomies(): array
	{
		$tax_const = defined( 'PLATHIX_TAXONOMY' ) ? (string) PLATHIX_TAXONOMY : 'plathix_folder';
		$saved_new = (array) get_option( 'plathix_taxonomies', [] );

		$all = array_map(
			'sanitize_key',
			array_merge(
				[ $tax_const ],
				$saved_new
			)
		);

		return array_values( array_filter( array_unique( $all ) ) );
	}

	/**
	 * @return list<string>
	 */

	private function tempDirs(): array
	{
		$temp_name = defined( 'PLATHIX_TEMP_DIR' ) ? (string) PLATHIX_TEMP_DIR : 'plathix-temp';
		$dirs      = [ ( new \Plathix\Infrastructure\TempDirectory() )->path() ];

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$dirs[] = trailingslashit( (string) $upload['basedir'] ) . $temp_name;
		}

		return array_values( array_unique( array_filter( $dirs ) ) );
	}

	private function deleteDirContents(string $dir): void
	{
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}

		foreach ( glob( rtrim( $dir, '/\\' ) . '/*' ) ?: [] as $entry ) {
			if ( is_dir( $entry ) && ! is_link( $entry ) ) {
				$this->deleteDirContents( $entry );
				@rmdir( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied temp subdir during full data wipe
				continue;
			}

			if ( is_file( $entry ) ) {
				wp_delete_file( $entry );
			}
		}
	}
}
