<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Cache
{
	private const MAX_TRANSIENT_KEY_LEN = 150;

	public const DASHBOARD_STATS_GROUP = 'dashboard_stats';

	/** @var array<int, bool> */
	private static array $metadata_processed = [];

	private function __construct(
		private readonly bool $use_object_cache = false
	) {
	}

	public static function make(): self {
		return new self(function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false);
	}

	public static function makeForTest(bool $use_object_cache = false): self {
		return new self($use_object_cache);
	}

	public function get(string $key): mixed {
		$storage_key = self::storage_key($key);

		if ( $this->use_object_cache ) {
			return wp_cache_get($storage_key, 'plathix');
		}

		return get_transient($storage_key);
	}

	public function set(string $key, mixed $value, int $ttl = 300): void {
		$storage_key = self::storage_key($key);

		if ( $this->use_object_cache ) {
			wp_cache_set($storage_key, $value, 'plathix', $ttl);
			return;
		}

		set_transient($storage_key, $value, 0 === $ttl ? YEAR_IN_SECONDS : $ttl);
	}

	public function delete(string $key): void {
		$storage_key = self::storage_key($key);

		wp_cache_delete($storage_key, 'plathix');
		delete_transient($storage_key);
	}

	public function bump_version(string $group): void {
		global $wpdb;

		$option_name = self::version_option_name($group);

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- atomic write (INSERT ... ON DUPLICATE KEY UPDATE) bumping the cache-group version counter; the primitive that drives caching, caching it is nonsensical
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, '1', 'no')
                 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$option_name
			)
		);

		$notoptions = wp_cache_get('notoptions', 'options');
		if ( is_array($notoptions) && isset($notoptions[ $option_name ]) ) {
			unset($notoptions[ $option_name ]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}

		wp_cache_delete($option_name, 'options');
	}

	public function versioned_key(string $group, string $key): string {
		return "{$group}:{$key}:v{$this->get_version( $group )}";
	}

	public function delete_group(string $group): void {
		$this->bump_version($group);
	}

	public static function on_metadata_update(mixed $metadata, int $attachment_id): mixed {
		if ( ! isset(self::$metadata_processed[ $attachment_id ]) ) {
			self::$metadata_processed[ $attachment_id ] = true;
			self::on_attachment_change();
		}

		return $metadata;
	}

	public static function on_attachment_change(mixed $arg = null, string $taxonomy = ''): void {
		$cache = self::make();

		$cache->delete_group('folders_' . ( $taxonomy ?: PLATHIX_TAXONOMY ));
		$cache->bump_version('folders_tree');
		$cache->bump_version('gallery_items');
		$cache->delete_group( self::DASHBOARD_STATS_GROUP );
	}

	/**
	 * Инвалидирует dashboard_stats немедленно на folder-мутациях (create/rename/move/delete),
	 * вместо ожидания часового TTL. Подписчик на существующий 'plathix/audit/record' хук
	 * (см. Plugin::boot()) — фильтрует по типу события, нерелевантные типы игнорирует.
	 */
	public static function on_folder_audit_event(string $type): void {
		$folder_events = [ 'folder_created', 'folder_renamed', 'folder_moved', 'folder_deleted' ];
		if ( in_array( $type, $folder_events, true ) ) {
			self::make()->delete_group( self::DASHBOARD_STATS_GROUP );
		}
	}

	private static function prefix(): string {
		return 'plathix_' . ( is_multisite() ? get_current_blog_id() . '_' : '' );
	}

	private static function storage_key(string $key): string {
		$full = self::prefix() . $key;

		if ( strlen($full) <= self::MAX_TRANSIENT_KEY_LEN ) {
			return $full;
		}

		return self::prefix() . 'h_' . md5($key);
	}

	private static function version_option_name(string $group): string {
		return self::prefix() . "ver_{$group}";
	}

	private function get_version(string $group): int {
		return max(0, (int) get_option(self::version_option_name($group), 0));
	}
}
