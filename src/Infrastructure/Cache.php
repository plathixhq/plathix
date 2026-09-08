<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Cache
{
	private const MAX_TRANSIENT_KEY_LEN = 150;

	public const DASHBOARD_STATS_GROUP = 'dashboard_stats';

	/** @var array<int, bool> */
	private static array $metadata_processed = [];

	/** @var array<int, bool> */
	private static array $upload_dedup_pending = [];

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
		$storageKey = self::storageKey($key);

		if ( $this->use_object_cache ) {
			return wp_cache_get($storageKey, 'plathix');
		}

		return get_transient($storageKey);
	}

	public function set(string $key, mixed $value, int $ttl = 300): void {
		$storageKey = self::storageKey($key);

		if ( $this->use_object_cache ) {
			wp_cache_set($storageKey, $value, 'plathix', $ttl);
			return;
		}

		set_transient($storageKey, $value, 0 === $ttl ? YEAR_IN_SECONDS : $ttl);
	}

	public function delete(string $key): void {
		$storageKey = self::storageKey($key);

		wp_cache_delete($storageKey, 'plathix');
		delete_transient($storageKey);
	}

	public function bumpVersion(string $group): void {
		global $wpdb;

		$option_name = self::versionOptionName($group);

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

	public function versionedKey(string $group, string $key): string {
		return "{$group}:{$key}:v{$this->getVersion( $group )}";
	}

	public function deleteGroup(string $group): void {
		$this->bumpVersion($group);
	}

	public static function onMetadataUpdate(mixed $metadata, int $attachment_id): mixed {
		if ( ! isset(self::$metadata_processed[ $attachment_id ]) ) {
			self::$metadata_processed[ $attachment_id ] = true;

			if ( isset(self::$upload_dedup_pending[ $attachment_id ]) ) {
				unset(self::$upload_dedup_pending[ $attachment_id ]);
			} else {
				self::onAttachmentChange();
			}
		}

		return $metadata;
	}

	public static function onAttachmentAdded(int $attachment_id): void {
		self::$upload_dedup_pending[ $attachment_id ] = true;
		self::onAttachmentChange($attachment_id);
	}

	public static function onAttachmentChange(mixed $arg = null, string $taxonomy = ''): void {
		$cache = self::make();

		$cache->deleteGroup('folders_' . ( $taxonomy ?: PLATHIX_TAXONOMY ));
		$cache->bumpVersion('folders_tree');
		$cache->bumpVersion('gallery_items');
		$cache->deleteGroup( self::DASHBOARD_STATS_GROUP );
	}

	public static function onFolderAuditEvent(string $type): void {
		$folder_events = [ 'folder_created', 'folder_renamed', 'folder_moved', 'folder_deleted' ];
		if ( in_array( $type, $folder_events, true ) ) {
			self::make()->deleteGroup( self::DASHBOARD_STATS_GROUP );
		}
	}

	private static function prefix(): string {
		return 'plathix_' . ( is_multisite() ? get_current_blog_id() . '_' : '' );
	}

	private static function storageKey(string $key): string {
		$full = self::prefix() . $key;

		if ( strlen($full) <= self::MAX_TRANSIENT_KEY_LEN ) {
			return $full;
		}

		return self::prefix() . 'h_' . md5($key);
	}

	private static function versionOptionName(string $group): string {
		return self::prefix() . "ver_{$group}";
	}

	private function getVersion(string $group): int {
		return max(0, (int) get_option(self::versionOptionName($group), 0));
	}
}
