<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\PublicApi\TrashApi;

/**
 * Единый движок полной очистки Free-данных плагина ([internal], WIPE-101).
 *
 * Один source-of-truth для сноса всего, что Free-ядро пишет в БД/на диск: опции, transient,
 * user_meta, таксономии+термины+termmeta+relationships, кастомная таблица пресетов, Action
 * Scheduler группы, cron-хуки, temp-директории. Вызывается из двух Free-входов:
 *   - runtime (кнопка danger-zone → AJAX) — прямой вызов класса;
 *   - штатный uninstall.php — процедурно ДУБЛИРУЕТ этот список (WP запускает uninstall без
 *     загрузки плагина, классы недоступны). При изменении набора ключей менять ОБА места
 *     (см. коммент-связку в uninstall.php).
 *
 * PRO-данные (таблица audit_log, PRO-cron, PRO-native token-опции) НЕ здесь: PRO автономен и
 * чистит себя сам (свой uninstall.php + подписчик на хук plathix/data_wipe/cleanup). Free про
 * PRO-таблицы не знает — инвариант автономии модулей (cross-package [internal]).
 *
 * Исключение — license-контракт (`Edition::STATUS_OPTION`/`KEY_OPTION`/`EXPIRES_OPTION`/
 * `LAST_CHECK_OPTION` + 2 PRO-native ключа + error-транзиент, см. wipe_options()): это не
 * PRO-данные в узком смысле — Free сам пишет/читает/удаляет их как источник истины
 * (`ProLicenseActions`), PRO лишь читает тот же контракт. LIKE-снос исключает их явно, иначе
 * удаление ТОЛЬКО Free стирает лицензию живого PRO ([internal]).
 *
 * ИНВАРИАНТ «не тронуть картинки»: ни одна операция не удаляет wp_posts (вложения/посты) и
 * файлы вложений. Снос папки = wp_delete_term (термин + связь термин↔файл), файл остаётся в
 * медиатеке — та же механика, что в FolderResetService.
 */
final class DataWiper
{
	/**
	 * Полная очистка Free-данных для одного блога.
	 *
	 * Вызывается уже в контексте нужного блога (switch_to_blog делает вызывающий: uninstall.php
	 * в цикле по сайтам / AJAX для текущего блога). Сам блог НЕ переключает.
	 *
	 * Все операции идемпотентны (DELETE / DROP TABLE IF EXISTS / wp_clear_scheduled_hook) —
	 * повторный вызов безопасен.
	 *
	 * @param int $blog_id Идентификатор блога (для Action Scheduler групп в multisite).
	 */
	public function wipe(int $blog_id): void
	{
		$this->wipe_terms();
		$this->drop_custom_tables();
		$this->wipe_trash_time_postmeta();
		$this->wipe_options();
		$this->wipe_user_meta($blog_id);
		$this->clear_action_scheduler_groups($blog_id);
		$this->clear_cron_hooks();
		$this->wipe_temp_dirs();
		$this->wipe_presets_directory();
	}

	/**
	 * Снос всех папок-терминов плагина во всех его таксономиях + termmeta + relationships.
	 * wp_delete_term разрывает связь термин↔объект (файл остаётся) и каскадом чистит termmeta.
	 * Файлы (wp_posts) НЕ трогаются — теряют только принадлежность к папке.
	 */
	private function wipe_terms(): void
	{
		foreach ( $this->taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				// Таксономия не зарегистрирована в этом запросе (напр. CPT без PRO) — термины
				// всё равно надо снести: делаем это на уровне БД в drop-независимой ветке ниже.
				$this->delete_orphan_terms_for_taxonomy( $taxonomy );
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
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}

	/**
	 * Снос терминов таксономии, не зарегистрированной в текущем запросе (wp_delete_term
	 * требует зарегистрированную таксономию). Прямой снос из term-таблиц по taxonomy.
	 * Не трогает wp_posts — только term_taxonomy/term_relationships/terms/termmeta.
	 */
	private function delete_orphan_terms_for_taxonomy(string $taxonomy): void
	{
		global $wpdb;

		$tt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot cleanup of unregistered-taxonomy terms during full data wipe; caching irrelevant for teardown DELETEs
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);

		if ( ! $tt_ids ) {
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $term_in is intval-mapped ids; table names from $wpdb (core properties: termmeta, terms, term_taxonomy); one-shot teardown cleanup. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($term_in)" );
			$wpdb->query(
				"DELETE t FROM {$wpdb->terms} t
				 LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				 WHERE t.term_id IN ($term_in) AND tt.term_id IS NULL"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * DROP кастомных таблиц Free. Только пресеты — audit_log принадлежит PRO (не здесь).
	 * Имя таблицы формируется из $wpdb->prefix + литерала (не пользовательский ввод), поэтому
	 * прямая интерполяция безопасна. %i (WP 6.2+) технически доступен на текущем min WP
	 * плагина 7.0, но не даёт security-выигрыша здесь ([internal]) — оставлено как есть.
	 */
	private function drop_custom_tables(): void
	{
		global $wpdb;

		$presets_table = $wpdb->prefix . 'plathix_presets';
		$wpdb->query( "DROP TABLE IF EXISTS {$presets_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix + literal, not user input; %i (WP 6.2+) is available on our min WP 7.0 but adds no security benefit for a $wpdb->prefix-based literal; see class docblock
	}

	/**
	 * Снос postmeta Trash-модуля ([internal]): _plathix_trash_time переживал полное удаление
	 * плагина, если на момент wipe пост ещё физически не удалён retention-cron'ом и не
	 * восстановлен (restore снимает мету сам, Trash\Module::on_untrashed_post()). Ключ берётся
	 * через PublicApi\TrashApi::trashTimeMetaKey() — единый источник правды, не дублируется строкой
	 * ([internal]: cross-module internals hidden behind PublicApi facade).
	 * wp_posts/файлы вложений не трогаются — удаляется только meta-запись.
	 */
	private function wipe_trash_time_postmeta(): void
	{
		global $wpdb;

		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => ( new TrashApi() )->trashTimeMetaKey() ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- teardown: bulk delete of the plugin's own postmeta rows by indexed meta_key; uninstall-only write, caching N/A
	}

	/**
	 * Снос всех опций и transient плагина по префиксу plathix_, КРОМЕ license-контракта.
	 * LIKE ловит и динамические/будущие ключи (вердикт data-скептика против жёсткого списка).
	 *
	 * Исключение ([internal]): license-опции физически совпадают с LIKE 'plathix_%', но это
	 * общий Free/PRO storage-контракт — Free сам пишет/читает/удаляет их как источник истины
	 * (`Edition::*_OPTION`, `ProLicenseActions`), PRO читает тот же контракт (`Module::*_OPTION`,
	 * `LicenseGate`). Без исключения удаление ТОЛЬКО Free стирает лицензию живого PRO. 2 ключа
	 * (`plathix_license_instance`, `plathix_license_grace_since`) — PRO-native, `Edition` их не
	 * объявляет (Free их не использует), поэтому литералами, не константой.
	 */
	private function wipe_options(): void
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
	 * Suffix-семейства user_meta ([internal]/#586): базовое имя ключа + опциональный
	 * `_<post_type>` + условный (только is_multisite()) `_<blog_id>` в конце. Единственный
	 * source of truth для этого списка — `Preferences::blog_suffix()`,
	 * `HomeDashboardPage::blog_scoped_meta_key()`, PRO `AccessMetaKey::name()`; список ниже
	 * дублирует их набор БАЗОВЫХ имён вручную — `wipe_user_meta()` не может импортировать
	 * PRO-класс (Free не знает о PRO), поэтому нет единого owner'а формата между двумя
	 * репозиториями; если появится новое suffix-семейство, этот список надо обновить вручную.
	 *
	 * `true` — между базой и суффиксом допустим доп. сегмент (`_<post_type>`), поэтому
	 * LIKE-паттерн вставляет `%` перед суффиксом; `false` — суффикс идёт сразу после базы.
	 *
	 * @return array<string, bool>
	 */
	private function suffixed_user_meta_families(): array
	{
		return [
			'plathix_favorites'            => true,
			'plathix_open_folder_id'       => true,
			'plathix_onboarding_dismissed' => false,
			'plathix_migration_dismissed'  => false,
			'plathix_user_access'          => false, // PRO-ключ (AccessMetaKey) — Free его не читает ([internal]), но обязан корректно снести по формату при wipe.
		];
	}

	/**
	 * Снос user_meta плагина при wipe ОДНОГО блога сети. Two-class predicate ([internal]):
	 *
	 * (A) suffix-семейства (`suffixed_user_meta_families()`) — LIKE обязан включать
	 *     `_<$blog_id>` (текущий wipe-блок, НЕ get_current_blog_id() — метод уже вызывается
	 *     в контексте нужного блога, но $blog_id явно передан и это ближайший source of
	 *     truth) в конце паттерна. Без этого условия LIKE 'plathix_%' ловит суффиксный
	 *     вариант ДРУГОГО блога у shared-пользователя (состоит в нескольких блогах сети) —
	 *     это и есть [internal]: wipe блога A стирал plathix_favorites_<B> тоже, потому что
	 *     фильтр был только по членству в блоге A, не по формату ключа.
	 * (B) всё остальное `plathix_%` (класс "несуффиксный per-user ключ, их блог = членство")
	 *     — на сегодня фактически пуст (проверено чтением всех user_meta-обращений в src/ на
	 *     паковке), но паттерн намеренно НЕ сужен до жёсткого списка ([internal]/#185:
	 *     "LIKE ловит и динамические/будущие ключи" остаётся в силе для этого класса).
	 *     `NOT LIKE` вычитает базовые имена класса (A), чтобы не задвоить их же условием.
	 *
	 * [internal]: wp_usermeta — глобальная таблица сети (WP core $wpdb->global_tables),
	 * switch_to_blog() НЕ создаёт отдельную wp_{blog_id}_usermeta, в отличие от
	 * options/terms/term_taxonomy. get_users(['blog_id' => $blog_id]) — WP-native способ
	 * получить пользователей, реально принадлежащих этому сайту (через их
	 * wp_{blog_id}_capabilities meta); на single-site blog_id по умолчанию равен текущему
	 * сайту, суффикс пуст (is_multisite() === false), поведение класса (A) вырождается в
	 * (B) для тех же ключей — не регрессия.
	 *
	 * Legacy несуффиксные записи suffix-семейств (созданные до 2026-08-26, когда [internal]
	 * ещё не существовал) этим методом НЕ сносятся — атрибуция конкретному блогу для них
	 * физически невозможна (несуффиксный ключ не содержит blog_id). Их снос — только в
	 * network-wide `uninstall.php` сценарии, не здесь (per-blog wipe).
	 */
	private function wipe_user_meta(int $blog_id): void
	{
		global $wpdb;

		$user_ids = get_users( [ 'blog_id' => $blog_id, 'fields' => 'ID' ] );
		if ( ! $user_ids ) {
			return;
		}

		$user_id_in = implode( ',', array_map( 'intval', $user_ids ) );
		$blog_suffix = is_multisite() ? '_' . $blog_id : '';

		$suffix_conditions = [];
		$suffix_params      = [];
		$excluded_bases     = [];

		foreach ( $this->suffixed_user_meta_families() as $base => $allows_post_type ) {
			$pattern              = $allows_post_type
				? $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( $blog_suffix )
				: $wpdb->esc_like( $base . $blog_suffix );
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

	/**
	 * Удаление Action Scheduler групп плагина (все джобы в группе plathix_{blog}).
	 * Таблицы AS могут отсутствовать — проверяем перед DELETE.
	 */
	private function clear_action_scheduler_groups(int $blog_id): void
	{
		global $wpdb;

		foreach ( [ 'plathix_' . $blog_id ] as $group_slug ) {
			$groups_tbl  = $wpdb->prefix . 'actionscheduler_groups';
			$actions_tbl = $wpdb->prefix . 'actionscheduler_actions';
			$logs_tbl    = $wpdb->prefix . 'actionscheduler_logs';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table names from $wpdb->prefix (not user input); one-shot teardown cleanup of the plugin's own job group; advisory SHOW TABLES check and the DELETE block below are one logical operation, disabled together. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
			$exists = (
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups_tbl ) ) === $groups_tbl &&
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_tbl ) ) === $actions_tbl &&
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) ) === $logs_tbl
			);

			if ( ! $exists ) {
				continue;
			}

			$group_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT group_id FROM {$groups_tbl} WHERE slug = %s", $group_slug )
			);

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

	/** Снятие всех cron-хуков плагина. */
	private function clear_cron_hooks(): void
	{
		$hooks = [
			'plathix_cleanup_temp',
			'plathix_job_cleanup_temp',
			'plathix_job_import',
			'plathix_job_reorder',
			'plathix_job_orphan_cleanup',
			// [internal]: список консистентности с Deactivator.php/uninstall.php.
			'plathix_job_import_checkpoint_cleanup',
		];

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Рекурсивная очистка temp-директорий плагина (zip, stage). Файлы вложений НЕ трогаются. */
	private function wipe_temp_dirs(): void
	{
		foreach ( $this->temp_dirs() as $dir ) {
			$this->delete_dir_contents( $dir );
		}
	}

	/**
	 * Снос директории preview-файлов пресетов ([internal]): plathix/presets/* переживала
	 * полное удаление плагина, хотя связанная БД-таблица plathix_presets уже дропается в
	 * drop_custom_tables() — асимметрия "метаданные исчезли, файлы-сироты остались". Путь
	 * тот же относительный литерал, что уже использует Activator::ensure_presets_dir() и
	 * PresetUploadPipeline::store_preview() (uploads/plathix/presets), не temp-семантика —
	 * отдельная директория, отдельный метод, не смешивается с wipe_temp_dirs().
	 */
	private function wipe_presets_directory(): void
	{
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return;
		}

		$this->delete_dir_contents( trailingslashit( (string) $upload['basedir'] ) . 'plathix/presets' );
	}

	/**
	 * Полный список таксономий плагина: снапшот из опции (объединением, [internal]) +
	 * дефолт. Даёт полноту сноса для CPT-таксономий, даже если PRO уже деактивирован.
	 *
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
	 * [internal] (M18): включает путь из TempDirectory::path() (единый резолвер, который по
	 * умолчанию предпочитает WP_CONTENT_DIR/../plathix-temp — тот же путь, что использует
	 * JobDispatcher) — прежде здесь был только uploads-фолбэк, который на дефолтной
	 * конфигурации хостинга не совпадает с реально используемой temp-директорией плагина, и
	 * она переживала "удалить все данные". Uploads-путь оставлен ДОПОЛНИТЕЛЬНО (объединение,
	 * не замена) — на случай, если temp-файлы физически лежат там из-за смены конфигурации/
	 * фильтра plathix/infrastructure/temp_dir в прошлом.
	 *
	 * @return list<string>
	 */
	private function temp_dirs(): array
	{
		$temp_name = defined( 'PLATHIX_TEMP_DIR' ) ? (string) PLATHIX_TEMP_DIR : 'plathix-temp';
		$dirs      = [ ( new \Plathix\Infrastructure\TempDirectory() )->path() ];

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$dirs[] = trailingslashit( (string) $upload['basedir'] ) . $temp_name;
		}

		return array_values( array_unique( array_filter( $dirs ) ) );
	}

	private function delete_dir_contents(string $dir): void
	{
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}

		foreach ( glob( rtrim( $dir, '/\\' ) . '/*' ) ?: [] as $entry ) {
			if ( is_dir( $entry ) && ! is_link( $entry ) ) {
				$this->delete_dir_contents( $entry );
				@rmdir( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied temp subdir during full data wipe
				continue;
			}

			if ( is_file( $entry ) ) {
				wp_delete_file( $entry );
			}
		}
	}
}
