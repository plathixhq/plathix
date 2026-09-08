<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Determines whether a frontend request is a page-builder editor context that
 * should receive the media sidebar.
 *
 * Accepts raw parameters instead of reading superglobals so the logic can be
 * tested without WordPress being present.
 */
final class BuilderDetect
{
	/**
	 * @param string[] $post_types
	 * @param array<string, string> $query
	 */

	public static function isFrontendBuilderRequest(
		bool $is_admin,
		array $post_types,
		array $query
	): bool {
		if ( $is_admin ) {
			return false;
		}

		if ( ! in_array( 'attachment', $post_types, true ) ) {
			return false;
		}

		// Elementor: ?elementor-preview=<id> (iframe) or ?action=elementor
		if ( (string) ( $query['elementor-preview'] ?? '' ) !== '' ) {
			return true;
		}
		if ( ( $query['action'] ?? '' ) === 'elementor' ) {
			return true;
		}

		// Beaver Builder: opens on the frontend page with ?fl_builder
		if ( array_key_exists( 'fl_builder', $query ) ) {
			return true;
		}

		// Bricks: ?bricks=run
		if ( ( $query['bricks'] ?? '' ) === 'run' ) {
			return true;
		}

		// Brizy: ?brizy-edit or ?brizy-edit-iframe
		if ( array_key_exists( 'brizy-edit', $query ) || array_key_exists( 'brizy-edit-iframe', $query ) ) {
			return true;
		}

		// Divi frontend builder: ?et_fb (any non-empty value)
		if ( (string) ( $query['et_fb'] ?? '' ) !== '' ) {
			return true;
		}

		// Oxygen: ?ct_builder
		if ( array_key_exists( 'ct_builder', $query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param bool   $isAdmin
	 * @param string $elementorPreview
	 * @param string $action
	 * @param string $page
	 */

	public static function isAdminBuilderRequest(
		bool $isAdmin,
		string $elementorPreview,
		string $action,
		string $page
	): bool {
		if ( ! $isAdmin ) {
			return false;
		}

		// Elementor: real editor opens via ?action=elementor or ?elementor-preview=,
		// NOT via ?page=elementor* (those are dashboard/settings pages, not editors).
		if ( $elementorPreview !== '' ) {
			return true;
		}
		if ( $action === 'elementor' ) {
			return true;
		}

		// Other page builders that use a dedicated ?page= slug for the editor itself.
		return in_array( $page, [ 'bricks', 'bricks_builder', 'oxy_builder', 'ct_builder', 'fl-builder', 'et_theme_builder', 'et-fb', 'divi' ], true );
	}
}
