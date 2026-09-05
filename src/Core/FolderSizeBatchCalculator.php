<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Сумма размера файлов по массиву term_id одним batch-SQL ([internal],
 * [internal]). Тот же query-shape, что FolderCountCalculator::batch_counts()
 * ([internal]) — JOIN term_relationships→term_taxonomy→posts→postmeta, GROUP BY term_id
 * — но SUM размера файла вместо COUNT(*). Намеренно НЕ связан с FolderCountCalculator
 * классом (наследование/композиция) — переиспользуется только форма SQL-запроса,
 * копированием, чтобы не создать скрытый count↔size coupling между двумя разными
 * бизнес-правилами ([internal], wp-architecture-skeptic verdict).
 *
 * Намеренно НЕ фильтрует по AttachmentVisibility (в отличие от FolderCountCalculator) —
 * старый Http\FolderSizeCalculator::sum_bytes() считал ВСЕ attachments термина без
 * предиката видимости; добавление фильтра здесь изменило бы численный результат, что
 * вне scope этого фикса (батчинг запросов, не смена бизнес-правила "что считать").
 *
 * Размер файла читается из postmeta._wp_attachment_metadata['filesize'] (WP core пишет
 * это поле при генерации метаданных вложения, подтверждено живым стендом — [internal],
 * [internal]) — НЕ через filesystem stat (filesize()/is_file()/is_link()). Три
 * независимых проблемы старого filesystem-based подхода устранены этим источником:
 * OOM-риск на больших датасетах (был: весь буфер путей в памяти одновременно; теперь:
 * постраничный SQL с накопительным SUM, см. batch_bytes()), stat-шторм (был: до 3
 * filesystem syscall на файл; теперь: 0), и совместимость с любым offload/CDN-плагином,
 * который переопределяет физический путь файла через apply_filters('get_attached_file'),
 * но не трогает записанный размер в метаданных. ВАЖНО: значение может теоретически
 * разойтись с фактическим размером на диске, если внешний код перезаписал файл, не
 * обновив _wp_attachment_metadata — тот же класс допущения, что использует WP core сам
 * (Media Library список размера тоже читает эту мету, не stat'ит диск).
 */
final class FolderSizeBatchCalculator
{
	/**
	 * Верхний предел строк результата за один SQL-вызов batch_bytes(). Постраничный обход
	 * (LIMIT/OFFSET) не даёт сериализованным _wp_attachment_metadata (на живом стенде
	 * ~1.1KB на файл с полным sizes-массивом — тяжелее, чем путь файла) накапливаться в
	 * памяти неограниченно на больших датасетах ([internal] п.1, OOM). Величина — по
	 * прецеденту FolderAssignmentService::CHUNK_SIZE.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Сумма размера файлов во всех папках-потомках $folder_id (БЕЗ самой $folder_id) —
	 * та же query-форма, что FolderCountService::calculate_recursive_count_from_scratch()
	 * (get_terms 'child_of' → batch-калькулятор → array_sum), скопирована по форме, не
	 * переиспользована наследованием/композицией ([internal] wp-architecture-skeptic
	 * verdict запрещает связывать size- и count-калькуляторы). В отличие от того
	 * count-прецедента, здесь $folder_id намеренно НЕ добавляется в $subtree_ids: у
	 * size-домена own bytes и recursive-children bytes — два раздельных числа в REST
	 * ответе ([internal]), которые вызывающая сторона не складывает.
	 */
	public function batch_bytes_recursive(int $folder_id, string $taxonomy): int {
		$descendant_terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'child_of'   => $folder_id,
				'fields'     => 'ids',
			]
		);

		$subtree_ids = is_array( $descendant_terms ) ? array_map( 'intval', $descendant_terms ) : [];

		if ( empty( $subtree_ids ) ) {
			return 0;
		}

		$totals = $this->batch_bytes( $subtree_ids, $taxonomy );
		// [internal]: null = реальная SQL-ошибка — не суммируем как честный 0, вызывающая
		// сторона (Module::get_folder_size()) не должна закэшировать это как валидный факт.
		return null === $totals ? 0 : array_sum( $totals );
	}

	/**
	 * @param array<int> $term_ids
	 * @return array<int, int>|null [ term_id => total_bytes ], null при SQL-ошибке
	 *         ([internal]) — отличается от [] (валидно нет файлов/строк с filesize)
	 */
	public function batch_bytes(array $term_ids, string $taxonomy): ?array {
		global $wpdb;

		if ( empty( $term_ids ) ) {
			return [];
		}

		try {
			$safe_ids     = array_map( 'intval', $term_ids );
			$id_list      = implode( ',', $safe_ids );
			$taxonomy_esc = esc_sql( $taxonomy );

			$totals = [];
			$offset = 0;

			do {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is intval'd, $taxonomy_esc is esc_sql()'d, $wpdb->* are core table names, LIMIT/OFFSET go through prepare()
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT tt.term_id, pm.meta_value AS attachment_metadata
						   FROM {$wpdb->term_relationships} tr
						   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
						   JOIN {$wpdb->postmeta} pm       ON pm.post_id = tr.object_id AND pm.meta_key = '_wp_attachment_metadata'
						  WHERE tt.term_id IN ({$id_list})
						    AND tt.taxonomy = '{$taxonomy_esc}'
						  LIMIT %d OFFSET %d",
						self::PAGE_SIZE,
						$offset
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

				// [internal]: null = реальная SQL-ошибка на этой странице обхода —
				// пропагируем как null всего результата, не как честный частичный итог.
				if ( null === $rows ) {
					return null;
				}

				$row_count = 0;

				foreach ( (array) $rows as $row ) {
					++$row_count;

					$term_id  = (int) $row->term_id;
					$metadata = @unserialize( (string) $row->attachment_metadata ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unserialize() emits a warning on malformed data; caller only needs the false/array outcome, not the warning

					if ( ! is_array( $metadata ) || ! isset( $metadata['filesize'] ) || ! is_numeric( $metadata['filesize'] ) ) {
						continue;
					}

					$totals[ $term_id ] = ( $totals[ $term_id ] ?? 0 ) + (int) $metadata['filesize'];
				}

				$offset += self::PAGE_SIZE;
			} while ( self::PAGE_SIZE === $row_count );

			return $totals;
		} catch ( \Throwable $e ) {
			// Diagnostic log only under WP_DEBUG — no noise in production logs (same pattern as Core\FolderTreeService::create()).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'plathix: FolderSizeBatchCalculator::batch_bytes failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only under WP_DEBUG
			}

			// [internal]: исключение — тоже реальная ошибка, не "0 файлов" — та же
			// null-пропагация, что и для null-результата get_results() выше.
			return null;
		}
	}
}
