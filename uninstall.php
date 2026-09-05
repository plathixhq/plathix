<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( ! defined( 'PLATHIX_TAXONOMY' ) ) {
	define( 'PLATHIX_TAXONOMY', 'plathix_folder' );
}

if ( ! defined( 'PLATHIX_TEMP_DIR' ) ) {
	define( 'PLATHIX_TEMP_DIR', 'plathix-temp' );
}

if ( ! defined( 'PLATHIX_JOB_CLEANUP_TEMP' ) ) {
	define( 'PLATHIX_JOB_CLEANUP_TEMP', 'plathix_job_cleanup_temp' );
	define( 'PLATHIX_JOB_IMPORT', 'plathix_job_import' );
	define( 'PLATHIX_JOB_REORDER', 'plathix_job_reorder' );
	define( 'PLATHIX_JOB_ORPHAN_CLEANUP', 'plathix_job_orphan_cleanup' );
	// [internal]: список консистентности с Deactivator.php/DataWiper.php (все три места
	// дублируют список хуков независимо — uninstall.php не может грузить классы плагина,
	// unified source-of-truth недостижим здесь; синхронизация через regression-тест).
	define( 'PLATHIX_JOB_IMPORT_CHECKPOINT_CLEANUP', 'plathix_job_import_checkpoint_cleanup' );
}

/**
 * @return list<int>
 */
function plathix_uninstall_sites(): array {
	if ( is_multisite() ) {
		return array_map( 'intval', get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) );
	}

	return [ get_current_blog_id() ];
}

/**
 * @return list<string>
 */
function plathix_uninstall_taxonomies(): array {
	$saved_new  = (array) get_option( 'plathix_taxonomies', [] );
	$taxonomies = array_values(
		array_unique(
			array_map(
				'sanitize_key',
				array_merge(
					[ PLATHIX_TAXONOMY ],
					$saved_new
				)
			)
		)
	);

	return array_values( array_filter( $taxonomies ) );
}

/**
 * [internal] (M16): uninstall.php имеет собственные PHP-classы недоступными (процедурный
 * скрипт без composer autoload) — не может вызвать Plathix\Infrastructure\TempDirectory
 * напрямую. Консультирует тот же `plathix/infrastructure/temp_dir` фильтр, что и
 * TempDirectory::path(), напрямую через apply_filters(), чтобы уважать оператора,
 * переопределившего temp-путь (тот же принцип, что уже применён в
 * DataWiper::temp_dirs(), [internal]/M18 — там класс доступен, здесь нет).
 *
 * @return list<string>
 */
function plathix_uninstall_temp_dirs(): array {
	$dirs = [];

	$configured = apply_filters( 'plathix/infrastructure/temp_dir', '' );
	if ( is_string( $configured ) && $configured !== '' ) {
		$dirs[] = trailingslashit( $configured );
	}

	$upload = wp_upload_dir();
	if ( ! empty( $upload['basedir'] ) ) {
		$dirs[] = trailingslashit( (string) $upload['basedir'] ) . PLATHIX_TEMP_DIR;
	}

	// [internal]: этот путь (на уровень выше wp-content) больше не кандидат СОЗДАНИЯ в
	// TempDirectory::path() — WP.org запрещает запись плагина за пределы установки WP.
	// Он остаётся здесь только как цель УДАЛЕНИЯ: инсталляции, апгрейднутые со старой
	// версии плагина, могли физически накопить каталог по этому legacy-пути, и uninstall
	// обязан его подчистить (idempotent no-op, если каталога там нет).
	$dirs[] = WP_CONTENT_DIR . '/../plathix-temp';
	$dirs[] = rtrim( sys_get_temp_dir(), '/\\' ) . '/plathix-temp';

	return array_values( array_unique( $dirs ) );
}

/**
 * [internal] (M16): та же причина, что у plathix_uninstall_temp_dirs() выше — uninstall.php
 * не может вызвать Activator::ensure_presets_dir()/PresetUploadPipeline напрямую (классы
 * плагина не загружены). Путь — тот же относительный литерал 'plathix/presets', что уже
 * используют оба класса под uploads/basedir; отдельная функция, не расширение
 * plathix_uninstall_temp_dirs() — presets НЕ temp-семантика (другое назначение, не участвует
 * в plathix/infrastructure/temp_dir фильтре и sys_get_temp_dir-фолбэках).
 */
function plathix_uninstall_presets_dir(): string {
	$upload = wp_upload_dir();
	if ( empty( $upload['basedir'] ) ) {
		return '';
	}

	return trailingslashit( (string) $upload['basedir'] ) . 'plathix/presets';
}

function plathix_uninstall_delete_dir_contents(string $dir): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}

	foreach ( glob( rtrim( $dir, '/\\' ) . '/*' ) ?: [] as $entry ) {
		if ( is_dir( $entry ) && ! is_link( $entry ) ) {
			plathix_uninstall_delete_dir_contents( $entry );
			@rmdir( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied subdir during uninstall; WP_Filesystem is fragile in a top-level uninstall procedure (pulls wp-admin/includes/file.php + credentials)
			continue;
		}

		if ( is_file( $entry ) ) {
			wp_delete_file( $entry );
		}
	}
}

function plathix_uninstall_cleanup_site(int $blog_id): void {
	global $wpdb;

	if ( is_multisite() ) {
		switch_to_blog( $blog_id );
	}

	$all_taxonomies = plathix_uninstall_taxonomies();
	if ( $all_taxonomies !== [] ) {
		$tax_placeholders = implode( ', ', array_fill( 0, count( $all_taxonomies ), '%s' ) );

		// Прямой SQL, не wp_delete_term()/get_terms(): uninstall.php подключается WordPress
		// БЕЗ загрузки плагина (register_taxonomy() не вызван), значит is_taxonomy_hierarchical()
		// вернёт false для plathix-таксономий и wp_delete_term() тихо пропустит реассигн
		// детей родителю — новый баг (orphan term_relationships), не альтернативное решение.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query required (see comment above); one-shot uninstall cleanup, nothing to cache.
		$tt_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $tax_placeholders is a %s marker string built via array_fill; values passed as prepare() args, not user input.
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($tax_placeholders)",
				...$all_taxonomies
			)
		);

		if ( $tt_ids ) {
			$tt_ids = array_map( 'intval', $tt_ids );
			$tt_placeholders = implode( ', ', array_fill( 0, count( $tt_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query required, uninstall-only, nothing to cache.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $tt_placeholders is a %d marker string built via array_fill; intval-mapped ids passed as prepare() args, not user input.
					"DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($tt_placeholders)",
					...$tt_ids
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query required, uninstall-only, nothing to cache.
		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $tax_placeholders is a %s marker string built via array_fill; values passed as prepare() args, not user input.
				"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($tax_placeholders)",
				...$all_taxonomies
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query required, uninstall-only, nothing to cache.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $tax_placeholders is a %s marker string built via array_fill; values passed as prepare() args, not user input.
				"DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($tax_placeholders)",
				...$all_taxonomies
			)
		);

		if ( $term_ids ) {
			$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
			$term_placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query required, uninstall-only, nothing to cache.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $term_placeholders is a %d marker string built via array_fill; intval-mapped ids passed as prepare() args, not user input.
					"DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($term_placeholders)",
					...$term_ids
				)
			);

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $term_placeholders is a %d marker string built via array_fill; intval-mapped ids passed as prepare() args, not user input. Direct query required, uninstall-only, nothing to cache (multi-line SQL needs disable/enable).
			$wpdb->query(
				$wpdb->prepare(
					"DELETE t FROM {$wpdb->terms} t
                     LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                     WHERE t.term_id IN ($term_placeholders)
                       AND tt.term_id IS NULL",
					...$term_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
	}

	// Clean up Action Scheduler jobs.
	foreach ( [ 'plathix_' . $blog_id ] as $group_slug ) {
		$groups_tbl  = $wpdb->prefix . 'actionscheduler_groups';
		$actions_tbl = $wpdb->prefix . 'actionscheduler_actions';
		$logs_tbl    = $wpdb->prefix . 'actionscheduler_logs';

		// Advisory-проверка существования AS-таблиц (SHOW TABLES) перед их очисткой ниже —
		// wp_cache не применим (одноразовый uninstall-путь, кэшировать нечего использовать
		// повторно); ActionScheduler не даёт публичного WP API для group/action/log cleanup.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall advisory check, nothing to cache; no public AS API for this cleanup.
		$tables_exist = (
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups_tbl ) ) === $groups_tbl &&
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_tbl ) ) === $actions_tbl &&
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) ) === $logs_tbl
		);

		if ( $tables_exist ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix + hardcoded actionscheduler suffix (verified via SHOW TABLES above), not user input; %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
			$as_group_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT group_id FROM {$groups_tbl} WHERE slug = %s",
					$group_slug
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $as_group_id > 0 ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are $wpdb->prefix + hardcoded actionscheduler suffixes (verified via SHOW TABLES above), not user input; %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is (multi-line SQL needs disable/enable).
				$wpdb->query(
					$wpdb->prepare(
						"DELETE l FROM {$logs_tbl} l
                         INNER JOIN {$actions_tbl} a ON l.action_id = a.action_id
                         WHERE a.group_id = %d",
						$as_group_id
					)
				);
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix + hardcoded actionscheduler suffix (verified via SHOW TABLES above), not user input; %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$actions_tbl} WHERE group_id = %d",
						$as_group_id
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->delete( $groups_tbl, [ 'group_id' => $as_group_id ] );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// [internal]: license-контракт (plathix_license_*) физически совпадает с LIKE
	// 'plathix_%', но это общий Free/PRO storage-контракт (Free пишет/читает/удаляет как
	// источник истины через Edition::*_OPTION, PRO читает тот же контракт) — исключаем явно,
	// иначе удаление ТОЛЬКО Free стирает лицензию живого PRO. Список зеркалит
	// DataWiper::wipe_options() (Free/src/Modules/DataWipe/DataWiper.php) — литералами, не
	// константой Edition::*, файл процедурный и классы плагина не грузит.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall cleanup of the plugin's own options, nothing to cache.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
             WHERE ( option_name LIKE %s AND option_name NOT IN ( %s, %s, %s, %s, %s, %s ) )
                OR ( option_name LIKE %s AND option_name != %s )
                OR ( option_name LIKE %s AND option_name != %s )",
			$wpdb->esc_like( 'plathix_' ) . '%',
			'plathix_license_status',
			'plathix_license_key',
			'plathix_license_expires',
			'plathix_license_last_check',
			'plathix_license_instance',
			'plathix_license_grace_since',
			'_transient_' . $wpdb->esc_like( 'plathix_' ) . '%',
			'_transient_plathix_license_last_error',
			'_transient_timeout_' . $wpdb->esc_like( 'plathix_' ) . '%',
			'_transient_timeout_plathix_license_last_error'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( plathix_uninstall_temp_dirs() as $dir ) {
		plathix_uninstall_delete_dir_contents( $dir );
	}

	// [internal]: presets preview-файлы переживали uninstall, хотя связанная БД-таблица
	// plathix_presets дропается ниже — асимметрия "метаданные исчезли, файлы-сироты
	// остались". Синхронизировано с DataWiper::wipe_presets_directory() (runtime эквивалент).
	$plathix_presets_dir = plathix_uninstall_presets_dir();
	if ( $plathix_presets_dir !== '' ) {
		plathix_uninstall_delete_dir_contents( $plathix_presets_dir );
	}

	wp_clear_scheduled_hook( 'plathix_cleanup_temp' );
	wp_clear_scheduled_hook( PLATHIX_JOB_CLEANUP_TEMP );
	wp_clear_scheduled_hook( PLATHIX_JOB_IMPORT );
	wp_clear_scheduled_hook( PLATHIX_JOB_REORDER );
	wp_clear_scheduled_hook( PLATHIX_JOB_ORPHAN_CLEANUP );
	wp_clear_scheduled_hook( PLATHIX_JOB_IMPORT_CHECKPOINT_CLEANUP );

	// [internal] (M13): таблица пресетов — PER-BLOG (имя строится через $wpdb->prefix, который
	// на multisite меняется по блогам), НЕ единая. Дропаем здесь, ВНУТРИ switch_to_blog для
	// этого $blog_id, до restore_current_blog() ниже — иначе на multisite DROP после цикла
	// удалил бы только таблицу главного сайта, оставляя таблицы остальных блогов сети
	// осиротевшими навсегда. audit_log (PRO) здесь НЕ дропается — PRO чистит свою таблицу сам
	// (PRO/uninstall.php). Синхронизировано с DataWiper::drop_custom_tables() (runtime
	// эквивалент, не имеет этой проблемы — вызывается для одного блога через AJAX, не batch).
	$plathix_presets_table = $wpdb->prefix . 'plathix_presets';
	// Table name from $wpdb->prefix + literal, not user input; %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall teardown of the plugin's own tables; runs once with no WP bootstrap available, nothing to cache
	$wpdb->query( "DROP TABLE IF EXISTS {$plathix_presets_table}" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

	// [internal]: _plathix_trash_time postmeta переживала uninstall, если на момент удаления
	// плагина пост ещё физически не удалён retention-cron'ом и не восстановлен (restore снимает
	// мету сам, Trash\Module::on_untrashed_post()). Строковый литерал обязан совпадать с
	// Trash\Module::TRASH_TIME_META — класс здесь недоступен (см. докблок PLATHIX_JOB_*
	// констант выше про ту же причину). wp_postmeta — глобальная per-blog таблица (в отличие
	// от wp_usermeta ниже), DELETE по meta_key безопасен без доп. blog-фильтра.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall cleanup of the plugin's own postmeta rows, nothing to cache.
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_plathix_trash_time' ] );

	// [internal]: wp_usermeta — глобальная таблица сети (не переключается switch_to_blog(),
	// в отличие от options/terms/term_taxonomy выше в этой функции), поэтому DELETE по
	// голому meta_key LIKE стирал user_meta ВСЕХ пользователей ВСЕХ сайтов сети при удалении
	// плагина на одном сайте. get_users(['blog_id' => $blog_id]) сужает удаление до
	// пользователей, реально принадлежащих ЭТОМУ сайту — тот же паттерн, что
	// DataWiper::wipe_user_meta() (класс недоступен здесь: WP запускает uninstall.php без
	// загрузки плагина). ВНУТРИ switch_to_blog($blog_id) этой функции (не после цикла по
	// всем сайтам, как было раньше) — та же причина, что у DROP TABLE пресетов выше:
	// per-blog операция должна выполняться в per-blog контексте, не одним batch после цикла.
	//
	// [internal]: одного user_id-фильтра недостаточно. Suffix-семейства (Preferences.php,
	// HomeDashboardPage.php, PRO AccessMetaKey.php — [internal]/#586) несут `_<blog_id>` в
	// конце ключа; голый LIKE 'plathix_%' ловит суффиксный вариант ДРУГОГО блога у
	// shared-пользователя (состоит в нескольких блогах сети). Two-class predicate ниже
	// дословно синхронизирован с DataWiper::wipe_user_meta() — при изменении набора
	// suffix-семейств менять ОБА места. Список семейств НЕ уходит в общий source of truth
	// (Free класса здесь не грузит PRO) — это признанный residual sync risk, не решённый
	// этим фиксом: если появится новое suffix-семейство, оба места (этот файл и
	// DataWiper::wipe_user_meta()) надо обновить вручную.
	//
	// Legacy несуффиксные записи suffix-семейств (созданные до [internal], 2026-08-26)
	// этим циклом НЕ сносятся: `plathix_uninstall_cleanup_site()` вызывается per-blog
	// (`foreach` ниже в файле), нет отдельного network-wide-финального usermeta-блока —
	// снос legacy здесь per-blog воспроизвёл бы тот же баг (неверная атрибуция блогу для
	// ключа без blog_id). Оставлено намеренно нерешённым MVP-non-goal, не молчаливым
	// пропуском.
	$plathix_uninstall_user_ids = get_users( [ 'blog_id' => $blog_id, 'fields' => 'ID' ] );
	if ( $plathix_uninstall_user_ids ) {
		$plathix_uninstall_user_id_in = implode( ',', array_map( 'intval', $plathix_uninstall_user_ids ) );
		$plathix_uninstall_blog_suffix = is_multisite() ? '_' . $blog_id : '';

		// base => allows optional `_<post_type>` segment before the suffix
		$plathix_uninstall_suffixed_families = [
			'plathix_favorites'            => true,
			'plathix_open_folder_id'       => true,
			'plathix_onboarding_dismissed' => false,
			'plathix_migration_dismissed'  => false,
			'plathix_user_access'          => false,
		];

		$plathix_uninstall_suffix_conditions = [];
		$plathix_uninstall_suffix_params     = [];
		$plathix_uninstall_excluded_bases    = [];

		foreach ( $plathix_uninstall_suffixed_families as $plathix_uninstall_base => $plathix_uninstall_allows_pt ) {
			$plathix_uninstall_pattern             = $plathix_uninstall_allows_pt
				? $wpdb->esc_like( $plathix_uninstall_base ) . '%' . $wpdb->esc_like( $plathix_uninstall_blog_suffix )
				: $wpdb->esc_like( $plathix_uninstall_base . $plathix_uninstall_blog_suffix );
			$plathix_uninstall_suffix_conditions[] = 'meta_key LIKE %s';
			$plathix_uninstall_suffix_params[]     = $plathix_uninstall_pattern;
			$plathix_uninstall_excluded_bases[]    = $wpdb->esc_like( $plathix_uninstall_base ) . '%';
		}

		$plathix_uninstall_other_condition = 'meta_key LIKE %s';
		foreach ( $plathix_uninstall_excluded_bases as $plathix_uninstall_excluded_base ) {
			$plathix_uninstall_other_condition .= ' AND meta_key NOT LIKE %s';
		}

		$plathix_uninstall_where  = '( ' . implode( ' OR ', $plathix_uninstall_suffix_conditions ) . ' ) OR ( ' . $plathix_uninstall_other_condition . ' )';
		$plathix_uninstall_params = array_merge(
			$plathix_uninstall_suffix_params,
			[ $wpdb->esc_like( 'plathix_' ) . '%' ],
			$plathix_uninstall_excluded_bases
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $plathix_uninstall_user_id_in is intval-mapped ids from get_users(), not user input; meta_key patterns bound via prepare() below; one-shot uninstall cleanup, nothing to cache. Table name $wpdb->usermeta is a core $wpdb property; %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta}
				 WHERE ( {$plathix_uninstall_where} )
				   AND user_id IN ($plathix_uninstall_user_id_in)",
				...$plathix_uninstall_params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

foreach ( plathix_uninstall_sites() as $blog_id ) {
	plathix_uninstall_cleanup_site( $blog_id );
}

if ( is_multisite() ) {
	delete_site_option( 'plathix_network_svg_policy' );
}

do_action( 'plathix/delete_plugin_data' );
