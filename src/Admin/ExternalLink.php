<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Edition;

final class ExternalLink
{
	/**
	 * @param string $path
	 * @param string $placement
	 */

	public static function marketing(string $path, string $placement): string {
		$base = 'https://plathix.com/' . ltrim( $path, '/' );

		$params = [
			'utm_source'   => 'plathix-plugin',
			'utm_medium'   => 'plathix-admin',
			'utm_campaign' => 'plathix-plugin',
			'utm_content'  => self::normalizeToken( $placement ),
			'edition'      => Edition::isPro() ? 'pro' : 'free',
		];

		if ( defined( 'PLATHIX_VERSION' ) ) {
			$params['plugin_version'] = self::normalizeToken( (string) PLATHIX_VERSION );
		}

		$screen = self::currentScreen();
		if ( '' !== $screen ) {
			$params['screen'] = $screen;
		}

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( $params, $base );
		}

		return $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	private static function currentScreen(): string {
		if ( ! function_exists( 'sanitize_key' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) );
	}

	private static function normalizeToken(string $value): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9._-]+/', '-', $value ) ?? '';

		return trim( $value, '-.' );
	}
}
