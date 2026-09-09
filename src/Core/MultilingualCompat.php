<?php

declare(strict_types=1);

namespace Plathix\Core;

class MultilingualCompat
{
	/**
	 * Set the query language to bypass WPML/Polylang per-language filtering.
	 *
	 * Polylang respects 'lang' => 'all'.
	 * WPML respects 'lang' => '' (empty string disables its language JOIN).
	 *
	 * If Polylang is active but no languages are configured, do not set 'lang' at all
	 * (see isPolylangActiveWithoutLanguages() docblock for why).
	 */
	public static function suppressForQuery(\WP_Query $query): void {
		if ( static::isPolylangActiveWithoutLanguages() ) {
			return;
		}

		if ( static::isWpmlActive() ) {
			$query->set( 'lang', '' );
		} else {
			$query->set( 'lang', 'all' );
		}
	}

	/**
	 * Suppress multilingual language filtering in an array-based query.
	 *
	 * Both WPML and Polylang respect 'all' for array-based queries (ajax_query_attachments_args,
	 * rest_attachment_query) — unlike the WP_Query object path where WPML needs ''.
	 *
	 * If Polylang is active but no languages are configured, do not set 'lang' at all
	 * (see isPolylangActiveWithoutLanguages() docblock for why).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function suppressForArgs(array $args): array {
		if ( static::isPolylangActiveWithoutLanguages() ) {
			return $args;
		}

		$args['lang'] = 'all';
		return $args;
	}

	/**
	 * Returns true when WPML is active.
	 * Protected so test subclasses can override without namespace-level stubs.
	 */
	protected static function isWpmlActive(): bool {
		return defined( 'ICL_LANGUAGE_CODE' );
	}

	protected static function isPolylangActiveWithoutLanguages(): bool {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return false;
		}

		$languages = pll_languages_list();
		return is_array( $languages ) && $languages === [];
	}
}
