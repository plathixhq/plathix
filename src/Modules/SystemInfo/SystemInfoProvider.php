<?php

declare(strict_types=1);

namespace Plathix\Modules\SystemInfo;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Health\HealthCheckRegistry;
use Plathix\Infrastructure\TableExistenceChecker;
use Plathix\Infrastructure\TempDirectory;
use Plathix\PublicApi\SvgApi;

/**
 * Сбор диагностических данных для страницы System Info ([internal], [internal]).
 *
 * Выделен из Plathix\Admin\SystemInfoPage: тот класс смешивал сбор данных и рендер HTML. Теперь
 * provider ВЛАДЕЕТ сбором (версии, БД, ActionScheduler-джобы, SVG, health, окружение, плагины),
 * а SystemInfoPage только рендерит его результат. Все методы read-only диагностика без side-effects
 * (кроме GET_LOCK/RELEASE_LOCK-пробы в check_advisory_lock_value, которая сама за собой убирает).
 *
 * Контракт данных сохранён 1:1: методы возвращают те же массивы `{label, value, ok?}`, что раньше
 * возвращали private-методы page.
 *
 * [internal]: health-строки (cron, temp dir, stuck jobs, boot integrity, SVG sanitizer relevance)
 * читаются из {@see HealthCheckRegistry} — того же реестра, что питает бейдж «Всё в порядке» на
 * Главной. Провайдер больше не считает эти проверки сам, чтобы избежать рассинхрона между Главной
 * и System Info.
 */
final class SystemInfoProvider
{
	/**
	 * Health checks: cron, temp dir, stuck jobs, stale locks.
	 *
	 * @return array<int, array{label:string, value:string, ok:bool}>
	 */
	public function health_check_rows(): array {
		$rows = [];
		foreach ( ( new HealthCheckRegistry() )->checks() as $check ) {
			$rows[] = [
				'label' => $check['label'],
				'value' => $check['value'],
				'ok'    => $check['ok'],
			];
		}
		return $rows;
	}

	/**
	 * Проверяет наличие таблиц БД Plathix и возвращает статус каждой.
	 *
	 * `wp_plathix_audit_log` — журнал аудита целиком уехал в PRO ([internal],
	 * [internal]); на Free-инсталляции без PRO эта таблица ожидаемо отсутствует, поэтому
	 * строка не выводится вовсе, а не показывается ошибочно красной ([internal]).
	 *
	 * @return array<int, array{label:string, value:string, ok:bool}>
	 */
	public function db_tables_rows(): array {
		global $wpdb;

		$expected = [ $wpdb->prefix . 'plathix_presets' ];
		if ( defined( 'PLATHIX_PRO_VERSION' ) ) {
			$expected[] = $wpdb->prefix . 'plathix_audit_log';
		}

		$rows = [];
		foreach ( $expected as $table ) {
			$exists = TableExistenceChecker::exists( $table );
			$rows[] = [
				'label' => $table,
				'value' => $exists ? __( 'Exists', 'plathix' ) : __( 'Missing', 'plathix' ),
				'ok'    => $exists,
			];
		}

		return $rows;
	}

	/**
	 * Общая информация о плагине: версия, настройки, SVG, объектный кэш, блокировки.
	 *
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */
	public function plathix_info(): array {
		$post_types = [ 'attachment' ]; // CTAN-201: attachment-native
		$retention  = (int) get_option( 'plathix_audit_retention_days', 90 );

		// [internal]/#189: SVG-санитайзер читается из HealthCheckRegistry (тот же health-факт,
		// что питает бейдж на Главной). severity:'ignored' (SVG выключен) отображается здесь как
		// нейтраль (ok:null), не как ошибка — санитайзер не нужен, пока политика не sanitize.
		$svg_check        = ( new HealthCheckRegistry() )->svg_sanitizer();
		$svg_sanitizer_ok = 'ignored' === $svg_check['severity'] ? null : $svg_check['ok'];
		$svg_sanitizer_value = 'ignored' === $svg_check['severity'] && ! $svg_check['ok']
			? $svg_check['value'] . ' — ' . __( 'not required while SVG is disabled', 'plathix' )
			: $svg_check['value'];

			$rows = [
				[ 'label' => __( 'Plugin Version', 'plathix' ),      'value' => PLATHIX_VERSION ],
			];

			// [internal]: BUILD_INFO пишется сборочным скриптом (bin/build-test-zip.sh,
			// [internal]) внутрь shipped-артефакта — сверх номера версии показываем,
			// какой именно коммит/сборка реально стоит (диагностика "какой деплой реально на
			// проде", [internal]). Отсутствует на dev-checkout (не из собранного zip) — тогда
			// строка просто не добавляется, это не ошибка.
			$build_info_row = $this->build_info_row( PLATHIX_PATH, __( 'Plugin Build', 'plathix' ) );
			if ( null !== $build_info_row ) {
				$rows[] = $build_info_row;
			}

			$rows[] = [ 'label' => __( 'Enabled Post Types', 'plathix' ),  'value' => implode( ', ', $post_types ) ];
			$rows[] = [ 'label' => __( 'SVG Support', 'plathix' ),         'value' => ( new SvgApi() )->currentPolicyLabel() ];
			$rows[] = [ 'label' => __( 'SVG Sanitizer', 'plathix' ),       'value' => $svg_sanitizer_value, 'ok' => $svg_sanitizer_ok ];

			// Журнал аудита целиком PRO-функционал ([internal]) — на Free нет ни
			// таблицы, ни фактической ретенции; строка вводит в заблуждение, если показана
			// ([internal], находка ElenaAloo). Тот же guard-паттерн, что уже применён к
			// строке `wp_plathix_audit_log` в db_tables_rows() выше.
			if ( defined( 'PLATHIX_PRO_VERSION' ) ) {
				/* translators: %d: retention period in days. */
				$rows[] = [ 'label' => __( 'Audit Log Retention', 'plathix' ), 'value' => 0 === $retention ? __( 'Keep forever', 'plathix' ) : sprintf( __( '%d days', 'plathix' ), $retention ) ];

				// PRO — отдельный shipped-артефакт со своим BUILD_INFO ([internal]);
				// PLATHIX_PRO_PATH определена в PRO includes/bootstrap.php, доступна здесь
				// только когда PRO активен (тот же guard, что и строка выше).
				$pro_build_info_row = $this->build_info_row( \constant( 'PLATHIX_PRO_PATH' ), __( 'Plugin PRO Build', 'plathix' ) );
				if ( null !== $pro_build_info_row ) {
					$rows[] = $pro_build_info_row;
				}
			}

			$rows[] = [ 'label' => __( 'Object Cache', 'plathix' ),        'value' => wp_using_ext_object_cache() ? __( 'External (Redis/Memcached)', 'plathix' ) : __( 'Transients (DB)', 'plathix' ), 'ok' => wp_using_ext_object_cache() ];
			$rows[] = [ 'label' => __( 'MySQL Advisory Locks', 'plathix' ), 'value' => $this->check_advisory_lock_value() ];

			return $rows;
	}

	/**
	 * Читает BUILD_INFO (commit=/dirty=/built_at=, [internal]) из корня
	 * плагина и формирует строку System Info. Отсутствие файла (dev git-checkout,
	 * не из собранного zip) — штатный случай, не ошибка: возвращает null, строка
	 * просто не выводится.
	 *
	 * @return array{label:string, value:string, ok?:bool|null}|null
	 */
	private function build_info_row(string $plugin_path, string $label): ?array {
		$path = $plugin_path . 'BUILD_INFO';
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of the plugin's own BUILD_INFO file next to $plugin_path, not a remote URL; wp_remote_get() (the sniff's suggested alternative) does not apply here.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}

		$fields = [];
		foreach ( explode( "\n", trim( $contents ) ) as $line ) {
			[ $key, $value ] = array_pad( explode( '=', $line, 2 ), 2, '' );
			$fields[ $key ] = $value;
		}

		if ( '' === ( $fields['commit'] ?? '' ) ) {
			return null;
		}

		$commit   = substr( $fields['commit'], 0, 8 );
		$built_at = $fields['built_at'] ?? '';
		$dirty    = 'true' === ( $fields['dirty'] ?? 'false' );

		$value = $built_at !== '' ? sprintf( '%s (%s)', $commit, $built_at ) : $commit;

		return [
			'label' => $label,
			'value' => $value,
			'ok'    => $dirty ? false : null,
		];
	}

	/**
	 * Информация о сервере: PHP, MySQL, GD, ZipArchive, директории.
	 *
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */
	public function server_environment(): array {
		global $wpdb;

		$upload  = wp_upload_dir();
		// [internal] (M19/L6): единый резолвер вместо ручного расчёта — иначе диагностика
		// показывает не тот путь, что реально использует plugin runtime (см. докблок
		// TempDirectory.php: тот же рассинхром, из-за которого этот класс и был введён).
		$temp_dir = ( new TempDirectory() )->path();

		$server_software = $_SERVER['SERVER_SOFTWARE'] ?? '—'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- server signature for the diagnostics screen; sanitized on line 202 and escaped at render time in SystemInfoPage
		$gd_version      = '';
		if ( function_exists( 'gd_info' ) ) {
			$gd_version = gd_info()['GD Version'] ?? 'Available';
		}
		$zip_ok          = class_exists( 'ZipArchive' );
		$upload_writable = is_writable( $upload['basedir'] ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only diagnostic probe of the WP uploads basedir for the System Info panel; reports status only, writes nothing.
		$temp_writable   = '' !== $temp_dir && ( ! is_dir( $temp_dir ) || is_writable( $temp_dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only diagnostic probe of the plugin's own PLATHIX_TEMP_DIR for the System Info panel; reports status only, writes nothing.

		return [
			[ 'label' => __( 'Operating System', 'plathix' ),      'value' => PHP_OS_FAMILY . ' ' . php_uname( 'r' ) ],
			[ 'label' => __( 'Web Server', 'plathix' ),            'value' => sanitize_text_field( (string) $server_software ) ],
			[ 'label' => __( 'MySQL Version', 'plathix' ),         'value' => $wpdb->db_version() ],
			[ 'label' => __( 'PHP Version', 'plathix' ),           'value' => PHP_VERSION, 'ok' => version_compare( PHP_VERSION, '8.1', '>=' ) ],
			[ 'label' => __( 'PHP Memory Limit', 'plathix' ),      'value' => ini_get( 'memory_limit' ) ?: '—' ],
			[ 'label' => __( 'PHP Max Execution', 'plathix' ),     'value' => ( ini_get( 'max_execution_time' ) ?: '0' ) . 's' ],
			[ 'label' => __( 'PHP Upload Max', 'plathix' ),        'value' => ini_get( 'upload_max_filesize' ) ?: '—' ],
			[ 'label' => __( 'GD Library', 'plathix' ),            'value' => $gd_version ?: __( 'Not installed', 'plathix' ), 'ok' => $gd_version !== '' ],
			[ 'label' => __( 'ZipArchive', 'plathix' ),            'value' => $zip_ok ? __( 'Installed', 'plathix' ) : __( 'Not installed', 'plathix' ), 'ok' => $zip_ok ],
			[ 'label' => __( 'Upload Dir Writable', 'plathix' ),   'value' => $upload_writable ? __( 'Yes', 'plathix' ) : __( 'No', 'plathix' ), 'ok' => $upload_writable ],
			[ 'label' => __( 'Temp Dir', 'plathix' ),              'value' => $temp_dir ?: __( 'Not configured', 'plathix' ) ],
			[ 'label' => __( 'Temp Dir Writable', 'plathix' ),     'value' => $temp_writable ? __( 'Yes', 'plathix' ) : __( 'No', 'plathix' ), 'ok' => $temp_writable ],
		];
	}

	/**
	 * Информация о WordPress: версия, URL, мультисайт, лимиты, отладка.
	 *
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */
	public function wp_environment(): array {
		return [
			[ 'label' => __( 'WordPress Version', 'plathix' ),   'value' => get_bloginfo( 'version' ) ],
			[ 'label' => __( 'Site URL', 'plathix' ),            'value' => get_site_url() ],
			[ 'label' => __( 'Multisite', 'plathix' ),           'value' => is_multisite() ? __( 'Yes', 'plathix' ) : __( 'No', 'plathix' ) ],
			[ 'label' => __( 'Max Upload Size', 'plathix' ),     'value' => size_format( wp_max_upload_size() ) ],
			[ 'label' => __( 'WP Memory Limit', 'plathix' ),     'value' => WP_MEMORY_LIMIT ],
			[ 'label' => __( 'Permalink Structure', 'plathix' ), 'value' => get_option( 'permalink_structure' ) ?: __( 'Plain', 'plathix' ) ],
			[ 'label' => __( 'Language', 'plathix' ),            'value' => get_locale() ],
			[ 'label' => __( 'Timezone', 'plathix' ),            'value' => wp_timezone_string() ],
			[ 'label' => __( 'Debug Mode', 'plathix' ),          'value' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Active', 'plathix' ) : __( 'Inactive', 'plathix' ), 'ok' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ],
			[ 'label' => __( 'Debug Log', 'plathix' ),           'value' => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? __( 'Active', 'plathix' ) : __( 'Inactive', 'plathix' ) ],
		];
	}

	/**
	 * Информация об активной теме.
	 *
	 * @return array<int, array{label:string, value:string}>
	 */
	public function theme_info(): array {
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$rows   = [
			[ 'label' => __( 'Name', 'plathix' ),    'value' => $theme->get( 'Name' ) ],
			[ 'label' => __( 'Version', 'plathix' ), 'value' => $theme->get( 'Version' ) ],
			[ 'label' => __( 'Author', 'plathix' ),  'value' => wp_strip_all_tags( $theme->get( 'Author' ) ) ],
			[ 'label' => __( 'Child Theme', 'plathix' ), 'value' => $parent ? __( 'Yes', 'plathix' ) : __( 'No', 'plathix' ) ],
		];
		if ( $parent ) {
			$rows[] = [ 'label' => __( 'Parent Theme', 'plathix' ), 'value' => $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) ];
		}
		return $rows;
	}

	/**
	 * Список активных плагинов с версиями.
	 *
	 * @return array<int, array{label:string, value:string}>
	 */
	public function active_plugins(): array {
		$plugins = get_option( 'active_plugins', [] );
		$rows    = [];
		foreach ( $plugins as $plugin_file ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			if ( empty( $data['Name'] ) ) {
				continue;
			}
			$rows[] = [
				'label' => $data['Name'],
				'value' => 'v' . $data['Version'] . ( $data['Author'] ? ' — ' . wp_strip_all_tags( $data['Author'] ) : '' ),
			];
		}
		return $rows;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Проверяет доступность MySQL advisory locks. */
	private function check_advisory_lock_value(): string {
		if ( DbAdvisoryLock::is_supported() ) {
			return __( 'Available', 'plathix' );
		}
		return __( 'Unavailable — queue deduplication degraded', 'plathix' );
	}
}
