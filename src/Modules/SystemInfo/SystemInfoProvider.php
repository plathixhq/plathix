<?php

declare(strict_types=1);

namespace Plathix\Modules\SystemInfo;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Health\HealthCheckRegistry;
use Plathix\Infrastructure\TableExistenceChecker;
use Plathix\Infrastructure\TempDirectory;
use Plathix\PublicApi\SvgApi;

final class SystemInfoProvider
{
	/**
	 * Health checks: cron, temp dir, stuck jobs, stale locks.
	 *
	 * @return array<int, array{label:string, value:string, ok:bool}>
	 */
	public function healthCheckRows(): array {
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
	 * @return array<int, array{label:string, value:string, ok:bool}>
	 */

	public function dbTablesRows(): array {
		global $wpdb;

		$expected = [ $wpdb->prefix . 'plathix_presets' ];

		$rows = [];
		foreach ( $expected as $table ) {
			$exists = TableExistenceChecker::exists( $table );
			$rows[] = [
				'label' => $table,
				'value' => $exists ? __( 'Exists', 'plathix' ) : __( 'Missing', 'plathix' ),
				'ok'    => $exists,
			];
		}

		return (array) apply_filters( 'plathix/system_info/rows', $rows, 'db_tables' );
	}

	/**
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */

	public function plathixInfo(): array {
		$post_types = [ 'attachment' ]; // CTAN-201: attachment-native

		$svg_check        = ( new HealthCheckRegistry() )->svgSanitizer();
		$svg_sanitizer_ok = 'ignored' === $svg_check['severity'] ? null : $svg_check['ok'];
		$svg_sanitizer_value = 'ignored' === $svg_check['severity'] && ! $svg_check['ok']
			? $svg_check['value'] . ' — ' . __( 'not required while SVG is disabled', 'plathix' )
			: $svg_check['value'];

			$rows = [
				[ 'label' => __( 'Plugin Version', 'plathix' ),      'value' => PLATHIX_VERSION ],
			];

			$buildInfoRow = $this->buildInfoRow( PLATHIX_PATH, __( 'Plugin Build', 'plathix' ) );
			if ( null !== $buildInfoRow ) {
				$rows[] = $buildInfoRow;
			}

			$rows[] = [ 'label' => __( 'Enabled Post Types', 'plathix' ),  'value' => implode( ', ', $post_types ) ];
			$rows[] = [ 'label' => __( 'SVG Support', 'plathix' ),         'value' => ( new SvgApi() )->currentPolicyLabel() ];
			$rows[] = [ 'label' => __( 'SVG Sanitizer', 'plathix' ),       'value' => $svg_sanitizer_value, 'ok' => $svg_sanitizer_ok ];

			$rows[] = [ 'label' => __( 'Object Cache', 'plathix' ),        'value' => wp_using_ext_object_cache() ? __( 'External (Redis/Memcached)', 'plathix' ) : __( 'Transients (DB)', 'plathix' ), 'ok' => wp_using_ext_object_cache() ];
			$rows[] = [ 'label' => __( 'MySQL Advisory Locks', 'plathix' ), 'value' => $this->checkAdvisoryLockValue() ];

			return (array) apply_filters( 'plathix/system_info/rows', $rows, 'plathixInfo' );
	}

	/**
	 * @return array{label:string, value:string, ok?:bool|null}|null
	 */

	private function buildInfoRow(string $plugin_path, string $label): ?array {
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
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */

	public function serverEnvironment(): array {
		global $wpdb;

		$upload  = wp_upload_dir();

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
	 * @return array<int, array{label:string, value:string, ok?:bool|null}>
	 */

	public function wpEnvironment(): array {
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
	 * @return array<int, array{label:string, value:string}>
	 */

	public function themeInfo(): array {
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
	 * @return array<int, array{label:string, value:string}>
	 */

	public function activePlugins(): array {

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

	private function checkAdvisoryLockValue(): string {
		if ( DbAdvisoryLock::isSupported() ) {
			return __( 'Available', 'plathix' );
		}
		return __( 'Unavailable — queue deduplication degraded', 'plathix' );
	}
}
