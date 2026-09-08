<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetSchema
{
	public const SCHEMA_VERSION = '1';

	public static function maybeInstall(): void {
		if ( get_option('plathix_preset_schema_version') === self::SCHEMA_VERSION ) {
			return;
		}

		self::installTable();
	}

	public static function installTable(): void {
		global $wpdb;

		if ( \defined('ABSPATH') && \file_exists( \ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::tableName();
		$charset = \is_object($wpdb) && \method_exists($wpdb, 'get_charset_collate')
			? (string) $wpdb->get_charset_collate()
			: '';
		$sql     = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_type VARCHAR(32) NOT NULL DEFAULT 'custom',
            slug VARCHAR(191) NOT NULL,
            title VARCHAR(191) NOT NULL DEFAULT '',
            version VARCHAR(32) NOT NULL DEFAULT '1',
            description TEXT NULL,
            tags_json LONGTEXT NULL,
            author_name VARCHAR(191) NOT NULL DEFAULT '',
            author_url VARCHAR(255) NOT NULL DEFAULT '',
            preview_ref VARCHAR(255) NOT NULL DEFAULT '',
            storage_path VARCHAR(255) NOT NULL DEFAULT '',
            validation_status VARCHAR(32) NOT NULL DEFAULT 'valid',
            last_error_code VARCHAR(64) NOT NULL DEFAULT '',
            folder_count INT UNSIGNED NOT NULL DEFAULT 0,
            structure_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_applied_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY blog_slug (blog_id, slug),
            KEY source_type (source_type),
            KEY validation_status (validation_status)
        ) {$charset};";

		if ( function_exists('dbDelta') ) {
			dbDelta($sql);
		} elseif ( \is_object($wpdb) && \method_exists($wpdb, 'query') ) {
			$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table DDL (CREATE TABLE) at install; runs once, caching N/A; prepare() inapplicable to identifiers; no user input. %i is not a candidate here either: it binds a single identifier inside $wpdb->prepare() for DML, not a multi-line CREATE TABLE body run via plain $wpdb->query(); table name still comes from self::tableName() (prefix + literal), not user input.
		}

		update_option('plathix_preset_schema_version', self::SCHEMA_VERSION, false);
	}

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'plathix_presets';
	}
}
