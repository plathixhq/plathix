<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Static helpers for suppressing WPML/Polylang language filtering in Plathix queries.
 *
 * Platform-level helper ([internal]): lives in Core, not
 * Modules\Multilingual, because Core\FolderQuery calls it unconditionally regardless of
 * whether the Multilingual module is registered — a platform class must not depend on a
 * disable-able feature module (platform-standard.md P1/P3). Modules\Multilingual\
 * MultilingualIntegration calls these statically rather than extending this class.
 */
class MultilingualCompat
{
	/**
	 * Set the query language to bypass WPML/Polylang per-language filtering.
	 *
	 * Polylang respects 'lang' => 'all'.
	 * WPML respects 'lang' => '' (empty string disables its language JOIN).
	 *
	 * If Polylang is active but no languages are configured, do not set 'lang' at all
	 * (see is_polylang_active_without_languages() docblock for why).
	 */
	public static function suppress_for_query(\WP_Query $query): void {
		if ( static::is_polylang_active_without_languages() ) {
			return;
		}

		if ( static::is_wpml_active() ) {
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
	 * (see is_polylang_active_without_languages() docblock for why).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function suppress_for_args(array $args): array {
		if ( static::is_polylang_active_without_languages() ) {
			return $args;
		}

		$args['lang'] = 'all';
		return $args;
	}

	/**
	 * Returns true when WPML is active.
	 * Protected so test subclasses can override without namespace-level stubs.
	 */
	protected static function is_wpml_active(): bool {
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
	protected static function is_polylang_active_without_languages(): bool {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return false;
		}

		$languages = pll_languages_list();
		return is_array( $languages ) && $languages === [];
	}
}
