<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

/**
 * Builds default metadata and preview for a site-generated preset export.
 * All values are derived from the current WP site — no user input required.
 */
final class PresetExportDefaults
{
	/**
	 * Returns default metadata for the export form.
	 *
	 * @return array{title:string,slug:string,description:string,tags:string,author:string,author_url:string}
	 */
	public static function metadata(): array
	{
		$site_name = get_bloginfo( 'name' );
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host_slug = sanitize_key( str_replace( '.', '-', $host ) );
		$slug      = $host_slug . '-plathix-preset';

		$author      = self::current_user_name();
		$author_url  = self::current_user_url();
		$description = self::build_description( $site_name );
		$tags        = '#' . sanitize_key( str_replace( [ ' ', '.' ], '-', strtolower( $site_name ) ) );

		return [
			'title'       => $site_name . ' — Plathix Preset',
			'slug'        => $slug,
			'description' => $description,
			'tags'        => $tags,
			'author'      => $author,
			'author_url'  => $author_url,
		];
	}

	/**
	 * Resolves a preview image file to use for the export.
	 * Priority: site logo → site icon → plugin placeholder.
	 *
	 * @return array{tmp_name:string,name:string,size:int}|null  null if nothing is available
	 */
	public static function preview_file(): ?array
	{
		$path = self::resolve_site_image_path();

		if ( $path === null ) {
			$path = self::plugin_placeholder_path();
		}

		if ( $path === null || ! is_file( $path ) ) {
			return null;
		}

		$resized = self::resize_to_fit( $path );
		$target  = $resized ?? $path;

		$size = filesize( $target );
		if ( $size === false ) {
			return null;
		}

		$ext  = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		$name = 'preview.' . ( $ext !== '' ? $ext : 'png' );

		return [
			'tmp_name' => $target,
			'name'     => $name,
			'size'     => (int) $size,
		];
	}

	// ── private ───────────────────────────────────────────────────────────────

	private static function current_user_name(): string
	{
		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || $user->ID === 0 ) {
			return get_bloginfo( 'name' );
		}

		/** @var \WP_User&object{display_name:string,user_login:string} $user -- phpstan-wordpress stub omits declared WP_User properties */
		$display = trim( (string) $user->display_name );

		return $display !== '' ? $display : trim( $user->user_login );
	}

	private static function current_user_url(): string
	{
		$user = wp_get_current_user();
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			/** @var \WP_User&object{user_url:string} $user -- phpstan-wordpress stub omits declared WP_User properties */
			$url = trim( (string) $user->user_url );
			if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return $url;
			}
		}

		return home_url();
	}

	private static function build_description(string $site_name): string
	{
		$tagline = trim( (string) get_bloginfo( 'description' ) );

		if ( $tagline !== '' ) {
			return sprintf(
				/* translators: 1: site name, 2: tagline */
				__( 'Preset created for %1$s — %2$s using the Plathix plugin.', 'plathix' ),
				$site_name,
				$tagline
			);
		}

		return sprintf(
			/* translators: 1: site name */
			__( 'Preset created for %s using the Plathix plugin.', 'plathix' ),
			$site_name
		);
	}

	private static function resolve_site_image_path(): ?string
	{
		// Try site logo first
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$path = get_attached_file( $logo_id );
			if ( is_string( $path ) && is_file( $path ) && ! is_link( $path ) ) {
				return $path;
			}
		}

		// Try site icon
		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id > 0 ) {
			$path = get_attached_file( $icon_id );
			if ( is_string( $path ) && is_file( $path ) && ! is_link( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	private static function plugin_placeholder_path(): ?string
	{
		// Единый общий placeholder плагина ([internal]): один ассет
		// для экспорта пресетов и для «сломанной» галереи.
		$path = PLATHIX_ASSETS_PATH . 'img/placeholder.webp';

		return is_file( $path ) ? $path : null;
	}

	/**
	 * Resizes image to fit within 600×400 and stay under 300 KB.
	 * Returns path to resized temp file, or null if resize unavailable/unnecessary.
	 */
	private static function resize_to_fit(string $source_path): ?string
	{
		$size = (int) filesize( $source_path );
		$ext  = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );

		// Skip resize if already small enough and is a supported format
		if ( $size <= 200_000 && in_array( $ext, [ 'webp', 'png', 'jpg', 'jpeg' ], true ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return null;
		}

		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$editor->resize( 600, 400, false );
		$editor->set_quality( 82 );

		// База — общий TempDirectory (публичный платформенный контракт; path()
		// уже trailingslashed). PresetExportDefaults полностью статичен, поэтому
		// резолвер вызывается напрямую, а не инжектируется. Изоляция через префикс.
		$temp_path = ( new \Plathix\Infrastructure\TempDirectory() )->path() . 'plathix_export_preview_' . uniqid( '', true ) . '.png';
		$saved     = $editor->save( $temp_path );

		if ( is_wp_error( $saved ) || ! isset( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return null;
		}

		return $saved['path'];
	}
}
