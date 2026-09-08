<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Keys;
use Plathix\Infrastructure\Logger;
use Plathix\Modules\Preset\PresetSchema;

class Activator
{

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
				self::runForSite();
				restore_current_blog();
			}

			return;
		}

		self::runForSite();

		set_transient( Keys::transient( 'activation_redirect' ), 1, MINUTE_IN_SECONDS );
	}

	private static function runForSite(): void {
		self::ensureDefaultOptions();

		Taxonomy::registerAll();

		$existing_taxonomies = (array) get_option( 'plathix_taxonomies', [] );
		update_option(
			'plathix_taxonomies',
			array_values( array_unique( array_merge( $existing_taxonomies, self::collectRegisteredTaxonomies() ) ) )
		);

		flush_rewrite_rules();

		if ( ! get_option( 'plathix_db_version' ) ) {
			update_option( 'plathix_db_version', PLATHIX_VERSION );
		}

		self::detectTermStorageAtomicity();
		self::ensureUncategorizedTerms();

		self::ensureTempDir();
		self::ensurePresetsDir();

		PresetSchema::installTable();

		$jobs = new JobDispatcher();
		$jobs->dispatchRecurring( JobDispatcher::JOB_CLEANUP_TEMP, JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL );
		$jobs->dispatchRecurring( JobDispatcher::JOB_ORPHAN_CLEANUP, 30 * DAY_IN_SECONDS );
		$jobs->dispatchRecurring( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, DAY_IN_SECONDS );

		$jobs->dispatchRecurring( \Plathix\Modules\Trash\Module::RETENTION_JOB, \Plathix\Modules\Trash\Module::RETENTION_JOB_INTERVAL );

		$jobs->dispatchRecurring( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, JobDispatcher::JOB_FOLDER_COUNT_RECONCILE_INTERVAL );
	}

	private static function ensureDefaultOptions(): void {
		$legacy_svg_support = get_option( 'plathix_svg_support', null );
		$legacy_svg_roles   = get_option( 'plathix_svg_allowed_roles', null );

		add_option( 'plathix_svg_policy', \Plathix\Modules\Svg\SvgSettings::POLICY_SANITIZE );
		add_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		add_option( 'plathix_svg_safe_mode', is_multisite() );
		add_option( 'plathix_lazy_tree', false );
		add_option( 'plathix_default_folder_id', 0 );
		add_option( 'plathix_infinite_scroll', false );
		add_option( 'plathix_bulk_safe_mode', true );

		if ( is_bool( $legacy_svg_support ) ) {

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
	private static function collectRegisteredTaxonomies(): array {

		return [ PLATHIX_TAXONOMY ];
	}

	private static function detectTermStorageAtomicity(): void {
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

		update_option( 'plathix_terms_storage_atomic', $all_innodb, false );
		update_option( 'plathix_db_engine_map', $engine_map, false );
	}

	private static function ensureUncategorizedTerms(): void {
		foreach ( Taxonomy::getEnabledTaxonomies() as $taxonomy ) {
			$existing = get_term_by( 'slug', 'uncategorized', $taxonomy );
			if ( $existing instanceof \WP_Term ) {
				continue;
			}

			$result = wp_insert_term(
				'Uncategorized',
				$taxonomy,
				[
					'slug'   => 'uncategorized',
					'parent' => 0,
				]
			);

			if ( is_wp_error( $result ) ) {
				Logger::error( 'activator_ensure_uncategorized_term_failed', [ 'taxonomy' => $taxonomy ] );
			}
		}
	}

	private static function ensureTempDir(): void {
		self::ensureGuardedDir( ( new \Plathix\Infrastructure\TempDirectory() )->path() );
	}

	private static function ensurePresetsDir(): void {
		self::ensureGuardedDir( 'plathix/presets' );
	}

	private static function ensureGuardedDir(string $path): void {
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
