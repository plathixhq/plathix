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

	/**
	 * Returns true when Polylang is active but has zero configured languages.
	 *
	 * Polylang's own SQL builder treats 'lang' => 'all' as "compare against the set of
	 * configured languages" — when that set is empty, it injects an always-false `0 = 1`
	 * clause instead of skipping the language filter, silently emptying every query that
	 * asks for 'all'. This is a genuine Polylang edge case (site has the plugin active
	 * but nobody finished the language setup wizard yet), confirmed on a live stand:
	 * identical WP_Query with/without 'lang' => 'all' returned 38 vs 0 results for the
	 * same tax_query. Skipping the 'lang' assignment entirely in this state makes the
	 * query behave as if Polylang were inactive for it, which is the only safe fallback.
	 *
	 * Protected so test subclasses can override without a real Polylang install.
	 */
	protected static function isPolylangActiveWithoutLanguages(): bool {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return false;
		}

		$languages = pll_languages_list();
		return is_array( $languages ) && $languages === [];
	}
}
