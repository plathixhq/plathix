<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Keys;
use Plathix\Modules\Preset\PresetSchema;

class Activator
{
	// [internal] ([internal]): $network_wide — параметр, который WP core реально
	// передаёт register_activation_hook-callback'у. is_network_admin() проверяет контекст
	// ТЕКУЩЕГО HTTP-запроса, а не факт network-wide активации — при `wp plugin activate
	// plathix --network` (WP-CLI) is_network_admin() всегда false (нет HTTP-запроса в admin
	// UI вовсе), хотя $network_wide реально true. Подтверждено чтением WP core
	// (wp-admin/includes/plugin.php::activate_plugin(), wp-cli/extension-command).
	public static function run(bool $network_wide = false): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_for_site();
				restore_current_blog();
			}

			return;
		}

		self::run_for_site();

		// First-run: one-shot флаг для редиректа на главную плагина при первом входе в admin
		// ([internal], [internal]). Ставится ТОЛЬКО в single-site ветке —
		// при network-activation (ветка выше) не ставим, чтобы не редиректить суперадмина при
		// массовой активации. Редирект выполняет Modules\FreeFirstRun\Module на admin_init.
		// Transient (TTL 60s) — самоочистка, если admin_init так и не наступил.
		set_transient( Keys::transient( 'activation_redirect' ), 1, MINUTE_IN_SECONDS );
	}

	private static function run_for_site(): void {
		self::ensure_default_options();

		Taxonomy::register_all();

		// Снапшот таксономий для uninstall-очистки строим ОБЪЕДИНЕНИЕМ с существующим, не заменой
		// ([internal], [internal].4): при attachment-only (PRO неактивен) замена сузила бы
		// снапшот до [plathix] и CPT-таксономии выпали бы → их термины стали бы вечными орфанами,
		// недоступными uninstall.php. Накопительный список гарантирует очистку CPT-терминов даже
		// после downgrade. Симметрично SettingsSanitizer, который тоже объединяет.
		$existing_taxonomies = (array) get_option( 'plathix_taxonomies', [] );
		update_option(
			'plathix_taxonomies',
			array_values( array_unique( array_merge( $existing_taxonomies, self::collect_registered_taxonomies() ) ) )
		);

		flush_rewrite_rules();

		if ( ! get_option( 'plathix_db_version' ) ) {
			update_option( 'plathix_db_version', PLATHIX_VERSION );
		}

		self::detect_term_storage_atomicity();
		self::ensure_uncategorized_terms();
		// Trash-term создаёт Modules\Trash\Module::ensure_trash_terms на init ([internal],
		// канон A7 как Audit ниже): Free инфраструктуру корзины-надстройки не создаёт.
		self::ensure_temp_dir();
		self::ensure_presets_dir();
		// Журнал (таблица + cron) вынесен в PRO ([internal]): установку и
		// расписание берёт на себя PlathixPro\Modules\Audit\Module::boot() (maybe_install /
		// ensure_schedule). Без PRO журнала нет — Free его инфраструктуру не создаёт.
		PresetSchema::install_table();

		$jobs = new JobDispatcher();
		$jobs->dispatch_recurring( JobDispatcher::JOB_CLEANUP_TEMP, JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL );
		$jobs->dispatch_recurring( JobDispatcher::JOB_ORPHAN_CLEANUP, 30 * DAY_IN_SECONDS );
		$jobs->dispatch_recurring( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, DAY_IN_SECONDS );
		// [internal]: retention-cleanup planning перенесён сюда из Trash\Module::boot() — тот
		// вызывался на plugins_loaded (до готовности Action Scheduler data store), planning был
		// no-op на любом сайте. Activation hook гарантированно выполняется после полной загрузки
		// плагина — тот же паттерн, что три вызова выше.
		$jobs->dispatch_recurring( \Plathix\Modules\Trash\Module::RETENTION_JOB, \Plathix\Modules\Trash\Module::RETENTION_JOB_INTERVAL );
		// [internal]: reconcile recursive-счётчиков папок с живой SQL-истиной (daily,
		// self-healing — см. Infrastructure\Jobs\FolderCountReconcileJobRunner).
		$jobs->dispatch_recurring( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, JobDispatcher::JOB_FOLDER_COUNT_RECONCILE_INTERVAL );
	}

	private static function ensure_default_options(): void {
		$legacy_svg_support = get_option( 'plathix_svg_support', null );
		$legacy_svg_roles   = get_option( 'plathix_svg_allowed_roles', null );

		// CTAN-103: сев настройки типов удалён — она целиком принадлежит PRO
		// (ContentTypes-модуль: сев, санитайзер, UI). Free о ней не знает.
		// [internal] ([internal]): сев `plathix_role_access`,
		// `plathix_audit_retention_days`, `plathix_quick_edit` убран отсюда — все три
		// PRO-owned, Free не должен создавать состояние для функциональности, которой без
		// PRO не существует. Теперь их сеют сами PRO-модули-владельцы
		// (Access\Module/Audit\Module/AttachmentMeta\Module) при своём boot(), включая
		// legacy-миграцию role_access (переехала в PlathixPro\Modules\Access\RolePolicy).
		// [internal]: SVG-настройка = трёхзначная политика. Чистая установка = 'sanitize'
		// (svg разрешён и очищается Plathix — security-фича активна по умолчанию).
		add_option( 'plathix_svg_policy', \Plathix\Modules\Svg\SvgSettings::POLICY_SANITIZE );
		add_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		add_option( 'plathix_svg_safe_mode', is_multisite() );
		add_option( 'plathix_lazy_tree', false );
		add_option( 'plathix_default_folder_id', 0 );
		add_option( 'plathix_infinite_scroll', false );
		add_option( 'plathix_bulk_safe_mode', true );

		if ( is_bool( $legacy_svg_support ) ) {
			// Legacy boolean → политика ([internal]): было включено → sanitize; было выключено →
			// block (сохраняем намерение «svg не принимается», теперь как явный запрет).
			update_option(
				'plathix_svg_policy',
				$legacy_svg_support
					? \Plathix\Modules\Svg\SvgSettings::POLICY_SANITIZE
					: \Plathix\Modules\Svg\SvgSettings::POLICY_BLOCK
			);
			update_option(
				'plathix_svg_support',
				is_array( $legacy_svg_roles ) && ! empty( $legacy_svg_roles )
					? array_values( array_map( 'sanitize_key', $legacy_svg_roles ) )
					: [ 'administrator', 'editor' ]
			);
		}

		if ( is_array( $legacy_svg_support ) ) {
			update_option( 'plathix_svg_support', array_values( array_map( 'sanitize_key', $legacy_svg_support ) ) );
		}

		if ( false !== get_option( 'plathix_svg_allowed_roles', false ) ) {
			delete_option( 'plathix_svg_allowed_roles' );
		}
	}

	/**
	 * @return list<string>
	 */
	private static function collect_registered_taxonomies(): array {
		// CTAN-103: Free регистрирует ровно одну таксономию. CPT-слаги в снапшот merge-ит их
		// регистратор — PRO (activation/boot/сохранение опции); merge выше идемпотентен, значит
		// Free-активация никогда не сужает накопленный список (страховка [internal].4 сохранена).
		return [ PLATHIX_TAXONOMY ];
	}

	private static function detect_term_storage_atomicity(): void {
		global $wpdb;

		$term_tables  = [
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->termmeta,
			$wpdb->term_relationships,
		];
		$placeholders = implode( ',', array_fill( 0, count( $term_tables ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is array_fill('%s',...) (each carries %s); values bound via ...$term_tables; not injectable; can't use WP table names in prepare()
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- install-time information_schema engine probe; runs once at activation, not on front-end path, caching N/A
			$wpdb->prepare(
				"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
				...$term_tables
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// [internal]: null = реальная SQL-ошибка (information_schema недоступен/запрос
		// упал) — не персистим на этой ветке ложный "atomic=true" (дефолт $all_innodb),
		// который никогда бы не пересчитался (эта функция вызывается только при активации).
		if ( null === $rows ) {
			return;
		}

		$all_innodb = true;
		$engine_map = [];

		foreach ( $rows as $row ) {
			$engine_map[ $row->TABLE_NAME ] = $row->ENGINE;
			if ( 'innodb' !== strtolower( (string) $row->ENGINE ) ) {
				$all_innodb = false;
			}
		}

		// autoload=false: диагностика install-time (из information_schema), не request-hot
		// продуктовое состояние. Не грузить на каждом запросе ([internal] #2).
		update_option( 'plathix_terms_storage_atomic', $all_innodb, false );
		update_option( 'plathix_db_engine_map', $engine_map, false );
	}

	private static function ensure_uncategorized_terms(): void {
		foreach ( Taxonomy::get_enabled_taxonomies() as $taxonomy ) {
			$existing = get_term_by( 'slug', 'uncategorized', $taxonomy );
			if ( $existing instanceof \WP_Term ) {
				continue;
			}

			wp_insert_term(
				'Uncategorized',
				$taxonomy,
				[
					'slug'   => 'uncategorized',
					'parent' => 0,
				]
			);
		}
	}


	/**
	 * [internal] (M21): guard'ит РЕАЛЬНЫЙ runtime temp dir, не хардкодженный
	 * uploads/$subpath — TempDirectory::path() (тот же резолвер, что использует
	 * JobDispatcher/DataWiper/uninstall.php) предпочитает WP_CONTENT_DIR/../plathix-temp
	 * и sys_get_temp_dir() ДО uploads-фолбэка; на типовых layout'ах guard раньше
	 * защищал не ту директорию.
	 */
	private static function ensure_temp_dir(): void {
		self::ensure_guarded_dir( ( new \Plathix\Infrastructure\TempDirectory() )->path() );
	}

	/**
	 * plathix/presets — каталог preview-изображений пресетов (PresetUploadPipeline::store_preview).
	 * Создаётся лениво при первом upload, но чистые установки должны получить guard при активации,
	 * иначе polyglot-preview (jpg+php) исполним по прямому URL при мисконфиге сервера ([internal]).
	 */
	private static function ensure_presets_dir(): void {
		self::ensure_guarded_dir( 'plathix/presets' );
	}

	/**
	 * Создаёт $path и кладёт directory-guard (index.php + .htaccess «Deny from all»),
	 * если их ещё нет. Единый инвариант защиты plugin-owned upload-каталогов от листинга и прямого
	 * исполнения содержимого. Идемпотентно (guard пишется только при отсутствии).
	 *
	 * [internal] (M21): $path может быть либо ОТНОСИТЕЛЬНЫМ subpath (строится под
	 * uploads/, как раньше — presets-кейс), либо уже АБСОЛЮТНЫМ путём (temp-dir-кейс —
	 * TempDirectory::path() может резолвить вне uploads/, напр.
	 * WP_CONTENT_DIR/../plathix-temp).
	 */
	private static function ensure_guarded_dir(string $path): void {
		if ( str_starts_with( $path, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) === 1 ) {
			$dir = rtrim( $path, '/\\' );
		} else {
			$upload = wp_upload_dir();
			if ( empty( $upload['basedir'] ) ) {
				return;
			}

			$dir = trailingslashit( $upload['basedir'] ) . $path;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- activation hook writes a directory-index guard into a plugin-owned just-created upload dir; WP_Filesystem credentials-flow may be unavailable during activation.
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- activation hook writes an Apache deny-all guard into a plugin-owned just-created upload dir; WP_Filesystem credentials-flow may be unavailable during activation.
		}
	}
}
