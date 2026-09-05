<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Чистый расчёт числа элементов в папках ([internal], [internal]).
 *
 * Вынесено из {@see FolderCountService}: подсчёт и кеш были смешаны в одном классе. Этот
 * класс держит ТОЛЬКО правила подсчёта (прямой SQL / wp_count_posts + предикат видимости
 * грида {@see AttachmentVisibility}) и НЕ знает про кеш. Кеш, инвалидацию и DTO-оркестрацию
 * держит FolderCountService, который делегирует расчёт сюда.
 *
 * Единый источник правила «считаем только видимое в гриде» — AttachmentVisibility
 * ([internal]): счётчик папки совпадает с числом в гриде, а не дрейфует
 * (баг 324 vs 319). SQL перенесён из FolderCountService байт-в-байт.
 */
final class FolderCountCalculator
{
	/**
	 * Число пользовательских папок (исключая системные — All Files/Uncategorized/Trash) для $taxonomy.
	 * Cold-path COUNT(*) без кеша (кеш держит FolderCountService::count_all выше по стеку).
	 */
	public function count_user_folders(string $taxonomy): int {
		global $wpdb;
		$taxonomy_esc = esc_sql( $taxonomy );
		// Системные slug — единый источник FolderRepository::system_slugs() ([internal]),
		// вместо хардкода литералов системных папок. Экранируем и собираем список для NOT IN.
		$system_slugs = array_map( static fn (string $slug): string => "'" . esc_sql( $slug ) . "'", FolderRepository::system_slugs() );
		$system_slugs_sql = implode( ',', $system_slugs );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $taxonomy_esc/$system_slugs_sql are esc_sql()'d per element before implode()
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
			  JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = '{$taxonomy_esc}'
			   AND t.slug NOT IN ({$system_slugs_sql})"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return max( 0, (int) $count );
	}

	public function trash_items_count(): int {
		$counts = wp_count_posts( 'attachment' );
		return (int) ( $counts->trash ?? 0 );
	}

	public function total_items_count(string $taxonomy): int {
		$post_type = Taxonomy::post_type_for_taxonomy( $taxonomy );

		// "All Files" is a visible counter next to the whole grid, so it must exclude the
		// same generator-hidden attachments as every folder ([internal]),
		// otherwise "All Files" (324) drifts from the sum of folders (319). wp_count_posts
		// aggregates by status and cannot carry a per-post predicate, so for attachments we
		// use a direct COUNT with the AttachmentVisibility predicate. Non-attachment types
		// have nothing hidden -> keep the cheap wp_count_posts sum.
		if ( 'attachment' === $post_type ) {
			global $wpdb;
			$post_type_esc     = esc_sql( $post_type );
			$visible_predicate = AttachmentVisibility::sql_predicate( 'p' );
			// [internal]: единый источник денylist-критерия trash/auto-draft — AttachmentVisibility.
			$status_predicate  = AttachmentVisibility::status_sql_predicate( 'p' );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $post_type_esc is esc_sql()'d, $visible_predicate/$status_predicate are self-built fragments, $wpdb->* are core table names
			$count = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				  WHERE p.post_type = '{$post_type_esc}'
				    AND {$status_predicate}
				    AND {$visible_predicate}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

			return max( 0, (int) $count );
		}

		$counts = wp_count_posts( $post_type );

		$sum = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( in_array( (string) $status, [ 'trash', 'auto-draft' ], true ) ) {
				continue;
			}
			$sum += (int) $count;
		}

		return max( 0, $sum );
	}

	/**
	 * Count items DIRECTLY in each term — descendants are NOT included.
	 * Returns [ term_id => count ] for all given term IDs, or `null` if the query itself
	 * failed (SQL error / uncaught exception) — distinct from a genuinely empty result.
	 *
	 * [internal]: this distinction exists because at least one caller
	 * (calculate_recursive_count_from_scratch(), the cold-seed path) writes its result to
	 * termmeta as a permanent fact — a caught error silently returning `[]` (indistinguishable
	 * from "these folders really have 0 items") would get `array_sum([]) = 0` written and
	 * frozen there forever (write_recursive_count()'s upsert is a deliberate no-op on an
	 * existing row, so a bad 0 is never naturally corrected). The other call sites
	 * (get_count()/get_counts_for()/get_all_cached()/get_children()) DO persist their
	 * result too (versioned response cache, CACHE_TTL) — this comment previously claimed
	 * otherwise, which [internal] found and closed: all four now explicitly skip the
	 * `cache->set()` on a `null` batch (a `sql_batch_failed` flag, not a silent `?? []`
	 * degrade that would have looked identical to a genuinely empty result), so a SQL
	 * failure never gets frozen as "this folder has 0 items" for the TTL window either.
	 *
	 * We join term_relationships → term_taxonomy by term_taxonomy_id (not term_id),
	 * so only rows that are explicitly assigned to this exact term are counted.
	 * WordPress's own $term->count on hierarchical taxonomies walks up the parent
	 * chain and inflates numbers — we intentionally avoid that here.
	 *
	 * @param array<int> $term_ids
	 * @return array<int, int>|null
	 */
	public function batch_counts(array $term_ids, string $taxonomy): ?array {
		global $wpdb;

		if ( empty( $term_ids ) ) {
			return [];
		}

		try {
			$post_type = Taxonomy::post_type_for_taxonomy( $taxonomy );

			// Build an integer-safe IN list without relying on prepare() spread.
			$safe_ids        = array_map( 'intval', $term_ids );
			$id_list         = implode( ',', $safe_ids );
			$taxonomy_esc    = esc_sql( $taxonomy );
			$post_type_esc   = esc_sql( $post_type );

			// Count only attachments the media grid actually shows — single source of that
			// rule is AttachmentVisibility ([internal]), so the folder
			// counter matches the grid total instead of drifting (324 vs 319). Stays one
			// batch COUNT: the exclude rule is a correlated NOT EXISTS in the same WHERE,
			// NOT a per-folder WP_Query. Empty exclude list -> '1=1' (no-op).
			$visible_predicate = AttachmentVisibility::sql_predicate( 'p' );
			// [internal]: единый источник денylist-критерия trash/auto-draft — AttachmentVisibility.
			$status_predicate  = AttachmentVisibility::status_sql_predicate( 'p' );

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is intval'd, $taxonomy_esc/$post_type_esc are esc_sql()'d, $visible_predicate/$status_predicate are self-built fragments, $wpdb->* are core table names
			$rows = $wpdb->get_results(
				"SELECT tt.term_id, COUNT(p.ID) AS cnt
                   FROM {$wpdb->term_relationships} tr
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   JOIN {$wpdb->posts} p           ON p.ID = tr.object_id
                  WHERE tt.term_id IN ({$id_list})
                    AND tt.taxonomy = '{$taxonomy_esc}'
                    AND p.post_type = '{$post_type_esc}'
                    AND {$status_predicate}
                    AND {$visible_predicate}
                  GROUP BY tt.term_id"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// $wpdb->get_results() returns null on a genuine SQL error (vs [] for a valid
			// empty result) — [internal], propagate that distinction instead of masking it.
			if ( null === $rows ) {
				return null;
			}

			$counts = [];
			foreach ( (array) $rows as $row ) {
				$counts[ (int) $row->term_id ] = (int) $row->cnt;
			}

			return $counts;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function uncategorized_items_count(string $taxonomy): int {
		$post_type = Taxonomy::post_type_for_taxonomy( $taxonomy );

		// "Uncategorized" is a real folder with a sidebar counter shown next to its own
		// grid, so it must obey the same "actually shown in the grid" rule as every other
		// folder ([internal]). For attachments the "shown" rule is the
		// visibility predicate owned by AttachmentVisibility; for non-attachment types
		// nothing is hidden, so found_posts is enough.
		//
		// [internal] ([internal]/L9): the attachment branch used to load ALL
		// untagged ids into PHP (posts_per_page=-1) and drop hidden ones with filter_ids().
		// On a large library (10k+ uncategorized) that is ~47ms + a multi-MB id array on
		// every cold tree build. Replaced with one aggregate COUNT that applies the same
		// visibility rule as a correlated NOT EXISTS in SQL — same shape as get_batch_counts,
		// no id array in PHP. "Uncategorized" = attachment with no term in this taxonomy →
		// a NOT EXISTS over term_relationships. post_status IN ('inherit','private') is kept
		// exactly as before so the number matches the prior implementation 1:1.
		if ( 'attachment' === $post_type ) {
			global $wpdb;

			$taxonomy_esc      = esc_sql( $taxonomy );
			$visible_predicate = AttachmentVisibility::sql_predicate( 'p' );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $taxonomy_esc is esc_sql()'d, $visible_predicate is a self-built fragment (AttachmentVisibility), $wpdb->* are core table names, no user input interpolated.
			$count = $wpdb->get_var(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				  WHERE p.post_type = 'attachment'
				    AND p.post_status IN ('inherit','private')
				    AND NOT EXISTS (
				        SELECT 1
				          FROM {$wpdb->term_relationships} tr
				          JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				         WHERE tr.object_id = p.ID
				           AND tt.taxonomy = '{$taxonomy_esc}'
				    )
				    AND {$visible_predicate}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

			return max( 0, (int) $count );
		}

		$query = new \WP_Query(
			[
				'post_type'                => $post_type,
				'post_status'              => 'any',
				'fields'                   => 'ids',
				'posts_per_page'           => 1,
				'no_found_rows'            => false,
				'plathix_suppress_lang' => true,
				'tax_query'                => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query with NOT EXISTS is the only WP-native way to count posts missing this taxonomy ("Uncategorized"); not a hot request path.
					[
						'taxonomy' => $taxonomy,
						'operator' => 'NOT EXISTS',
					],
				],
			]
		);

		/** @var \WP_Query&object{found_posts:int} $query -- WP_Query::$found_posts exists at runtime; phpstan-wordpress stub omits it */
		return (int) $query->found_posts;
	}
}
