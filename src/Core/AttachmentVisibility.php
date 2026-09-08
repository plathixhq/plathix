<?php

declare(strict_types=1);

namespace Plathix\Core;

final class AttachmentVisibility
{
	/**
	 * @param string[] $keys
	 * @return string[]
	 */

	public const EXCLUDE_META_FILTER = 'plathix/count_exclude_meta';

	/**
	 * @return string[]
	 */

	public static function excludeMetaKeys(): array {
		$default = [ '_elementor_is_screenshot' ];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- EXCLUDE_META_FILTER constant already resolves to the prefixed literal 'plathix/count_exclude_meta'; static analysis doesn't evaluate the constant.
		$keys = apply_filters( self::EXCLUDE_META_FILTER, $default );

		if ( ! is_array( $keys ) ) {
			return $default;
		}

		$clean = [];
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				$clean[] = $key;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param string $posts_alias
	 */

	public static function sqlPredicate(string $posts_alias): string {
		$keys = self::excludeMetaKeys();
		if ( $keys === [] ) {
			return '1=1';
		}

		global $wpdb;
		$in_list = implode(
			',',
			array_map( static fn (string $k): string => "'" . esc_sql( $k ) . "'", $keys )
		);

		return "NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} plx_vis_m
			 WHERE plx_vis_m.post_id = {$posts_alias}.ID
			   AND plx_vis_m.meta_key IN ({$in_list})
		)";
	}

	/**
	 * @param array<int> $ids
	 * @return array<int>
	 */

	public static function filterIds(array $ids): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( $ids === [] ) {
			return [];
		}

		$keys = self::excludeMetaKeys();
		if ( $keys === [] ) {
			return $ids;

		}

		global $wpdb;
		$id_list = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is absint'd, predicate keys are esc_sql'd, $wpdb->* are core table names.
		$visible = $wpdb->get_col(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			  WHERE p.ID IN ({$id_list})
			    AND " . self::sqlPredicate( 'p' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$visible_set = array_flip( array_map( 'intval', (array) $visible ) );

		return array_values( array_filter( $ids, static fn (int $id): bool => isset( $visible_set[ $id ] ) ) );
	}

	/**
	 * @param list<string> $statuses
	 */

	public static function countVisible(array $statuses): int {

		return self::countVisibleOrNull( $statuses ) ?? 0;
	}

	/**
	 * @param list<string> $statuses
	 */

	public static function countVisibleOrNull(array $statuses): ?int {
		$statuses = array_values( array_unique( array_filter(
			array_map( static fn ($s): string => is_string( $s ) ? $s : '', $statuses ),
			static fn (string $s): bool => $s !== ''
		) ) );
		if ( $statuses === [] ) {
			return 0;
		}

		global $wpdb;
		$status_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", $statuses )
		);
		$visible_predicate = self::sqlPredicate( 'p' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_list is esc_sql'd, $visible_predicate is a self-built fragment, $wpdb->posts is a core table name; no raw user input
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			  WHERE p.post_type = 'attachment'
			    AND p.post_status IN ({$status_list})
			    AND {$visible_predicate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return SqlSafeCast::nullSafeSqlCount( $count );
	}

	private const NON_VISIBLE_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * @param string $posts_alias
	 */

	public static function statusSqlPredicate(string $posts_alias): string {
		$in_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", self::NON_VISIBLE_STATUSES )
		);

		return "{$posts_alias}.post_status NOT IN ({$in_list})";
	}

	public static function isVisibleStatus(string $post_status): bool {
		return ! in_array( $post_status, self::NON_VISIBLE_STATUSES, true );
	}

	public static function isVisibleByMeta(int $attachment_id): bool {
		foreach ( self::excludeMetaKeys() as $key ) {
			if ( metadata_exists( 'post', $attachment_id, $key ) ) {
				return false;
			}
		}

		return true;
	}

	public static function isVisibleByMetaExcept(int $attachment_id, string $ignored_key): bool {
		foreach ( self::excludeMetaKeys() as $key ) {
			if ( $key === $ignored_key ) {
				continue;
			}
			if ( metadata_exists( 'post', $attachment_id, $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<string> $statuses
	 * @param string        $posts_alias
	 */

	public static function statusInPredicate(array $statuses, string $posts_alias): string {
		$statuses = array_values( array_unique( array_filter(
			array_map( static fn ($s): string => is_string( $s ) ? $s : '', $statuses ),
			static fn (string $s): bool => $s !== ''
		) ) );
		if ( $statuses === [] ) {
			return '1=0';
		}

		$in_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", $statuses )
		);

		return "{$posts_alias}.post_status IN ({$in_list})";
	}
}
