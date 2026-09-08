<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class RequestContext
{
	private static string $post_type = '';
	private static string $screen_id = '';
	private static bool $isActive = false;
	private static bool $initialized = false;

	public function __construct(Loader $loader) {
		$loader->addAction( 'currentScreen', $this, 'init', 5 );
	}

	public static function getPostType(): string {
		if ( ! self::$isActive ) {
			throw new \LogicException(
				'RequestContext::getPostType() called when isActive=false. Use TaxonomyResolver::fromPostType( $post_type ) outside admin UI.'
			);
		}

		return self::$post_type;
	}

	public static function getScreenId(): string {
		return self::$screen_id;
	}

	public static function isActive(): bool {
		return self::$isActive;
	}

	public function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		self::$post_type = self::resolvePostTypeFromScreen();
		self::$isActive = self::$post_type !== '';

		global $currentScreen;
		self::$screen_id = ( $currentScreen instanceof \WP_Screen ) ? (string) $currentScreen->id : '';
	}

	private static function resolvePostTypeFromScreen(): string {
		global $pagenow, $currentScreen;

		if ( is_admin() && $currentScreen instanceof \WP_Screen ) {
			$screen_post_type = (string) $currentScreen->post_type;

			if ( $screen_post_type !== '' ) {
				return $screen_post_type;
			}

			if ( $currentScreen->base === 'upload' ) {
				return 'attachment';
			}
		}

		return match ( true ) {
			is_admin() && $pagenow === 'upload.php' => 'attachment',
			is_admin() && $pagenow === 'post.php' => get_post_type( absint( wp_unslash( $_GET['post'] ?? 0 ) ) ) ?: 'post', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fallback for when WP_Screen is unavailable; read-only navigation parameter, no form processing and no DB write
			is_admin() && $pagenow === 'post-new.php' => sanitize_key( (string) wp_unslash( $_GET['post_type'] ?? 'post' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fallback for when WP_Screen is unavailable; read-only navigation parameter, no form processing and no DB write

			self::isPageBuilder() => 'attachment',
			default => '',
		};
	}

	public static function isPageBuilderRequest(): bool {
		return self::isPageBuilder();
	}

	private static function isPageBuilder(): bool {
		return BuilderDetect::isAdminBuilderRequest(
			is_admin(),
			(string) wp_unslash( $_GET['elementor-preview'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- non-empty check only, value is never output; read-only screen-resolution from WP navigation params, no form processing, no DB write
			sanitize_key( (string) wp_unslash( $_GET['action'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution from WP navigation params ($pagenow context); no form processing, no DB write
			sanitize_key( (string) wp_unslash( $_GET['page'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution from WP navigation params ($pagenow context); no form processing, no DB write
		);
	}

	public static function resetForTest(): void {
		self::$post_type = '';
		self::$screen_id = '';
		self::$isActive = false;
		self::$initialized = false;
	}
}
