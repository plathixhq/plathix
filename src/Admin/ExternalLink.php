<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Edition;

final class ExternalLink
{
	/**
	 * Собирает marketing-URL на plathix.com с UTM-метками.
	 *
	 * Домен сайта клиента в метки НЕ попадает ([internal]): он является идентификатором
	 * конкретной установки, а Guideline 7 WP.org запрещает сбор данных о сайте без явного
	 * согласия. Передаются только характеристики среды — версия плагина, редакция и экран,
	 * — одинаковые у множества сайтов и никого не идентифицирующие. Модель заимствована у
	 * Yoast SEO (`Short_Link_Helper::collect_additional_shortlink_data()`), где по той же
	 * причине нет ни `home_url()`, ни `site_url()`.
	 *
	 * @param string $path      Путь на plathix.com, например `/pro/`.
	 * @param string $placement Место клика; едет в `utm_content` для различения источников.
	 */
	public static function marketing(string $path, string $placement): string {
		$base = 'https://plathix.com/' . ltrim( $path, '/' );

		$params = [
			'utm_source'   => 'plathix-plugin',
			'utm_medium'   => 'plathix-admin',
			'utm_campaign' => 'plathix-plugin',
			'utm_content'  => self::normalize_token( $placement ),
			'edition'      => Edition::is_pro() ? 'pro' : 'free',
		];

		// Гард нужен: класс вызывается и из юнит-тестов, где константа не определена.
		if ( defined( 'PLATHIX_VERSION' ) ) {
			$params['plugin_version'] = self::normalize_token( (string) PLATHIX_VERSION );
		}

		$screen = self::current_screen();
		if ( '' !== $screen ) {
			$params['screen'] = $screen;
		}

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( $params, $base );
		}

		return $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Slug текущей admin-страницы или пустая строка вне страниц плагина.
	 *
	 * Пустое значение не попадает в query: support-footer живёт в том числе на `upload.php`,
	 * где `?page=` отсутствует, и метка `screen=` там была бы шумом.
	 */
	private static function current_screen(): string {
		if ( ! function_exists( 'sanitize_key' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing check
		return sanitize_key( (string) ( $_GET['page'] ?? '' ) );
	}

	private static function normalize_token(string $value): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9._-]+/', '-', $value ) ?? '';

		return trim( $value, '-.' );
	}
}
