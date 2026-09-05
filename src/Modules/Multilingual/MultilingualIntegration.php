<?php

declare(strict_types=1);

namespace Plathix\Modules\Multilingual;

use Plathix\Core\MultilingualCompat;
use Plathix\Loader;

/**
 * Keeps Plathix folder taxonomies shared across all languages.
 *
 * WPML and Polylang by default make custom taxonomies translatable,
 * which means each language gets its own copy of terms. For folder
 * taxonomies this is wrong — folders are site-wide, not per-language.
 *
 * This class:
 *   1. Marks all plathix taxonomies as non-translatable (shared).
 *   2. Disables language filtering in WP_Query when counting uncategorized items.
 *   3. Disables language filtering when filtering the media grid by folder.
 */
class MultilingualIntegration
{
	public function __construct(
		private readonly Loader $loader
	) {
		// Run after init (priority 20) so taxonomies are already registered.
		$this->loader->add_filter( 'wpml_is_translated_taxonomy', $this, 'is_translated_taxonomy', 10, 2 );
		$this->loader->add_filter( 'pll_is_translated_taxonomy', $this, 'is_translated_taxonomy_pll', 10, 2 );
		// [internal]: pll_is_translated_taxonomy больше не в основной цепочке Polylang 3.7+ —
		// pll_get_taxonomies (allowlist) сейчас реальная точка расширения. Оставляем оба —
		// дешёвая двойная страховка, старый фильтр не мешает, даже если Polylang его игнорирует.
		$this->loader->add_filter( 'pll_get_taxonomies', $this, 'exclude_plathix_taxonomies', 10, 2 );

		// Suppress language filter when Plathix runs its own queries.
		$this->loader->add_action( 'pre_get_posts', $this, 'suppress_language_filter', 1 );
		$this->loader->add_filter( 'ajax_query_attachments_args', $this, 'add_lang_all_ajax', 1 );
	}

	/**
	 * Tell WPML that all plathix taxonomies are not translatable.
	 *
	 * @param bool   $is_translated  Current value.
	 * @param string $taxonomy       Taxonomy slug.
	 */
	public function is_translated_taxonomy(bool $is_translated, string $taxonomy): bool {
		if ( $this->is_plathix_taxonomy( $taxonomy ) ) {
			return false;
		}

		return $is_translated;
	}

	/**
	 * Tell Polylang that all plathix taxonomies are not translatable.
	 *
	 * @param bool   $is_translated  Current value.
	 * @param string $taxonomy       Taxonomy slug.
	 */
	public function is_translated_taxonomy_pll(bool $is_translated, string $taxonomy): bool {
		return $this->is_translated_taxonomy( $is_translated, $taxonomy );
	}

	/**
	 * Remove WPML/Polylang language constraint from WP_Query when it is
	 * one of our internal queries (uncategorized count).
	 */
	public function suppress_language_filter(\WP_Query $query): void {
		if ( ! $query->get( 'plathix_suppress_lang' ) ) {
			return;
		}

		MultilingualCompat::suppress_for_query( $query );
	}

	/**
	 * For the media grid AJAX query, add lang=all so Polylang/WPML
	 * return attachments from all languages when filtering by folder.
	 *
	 * @param  array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function add_lang_all_ajax(array $args): array {
		if ( isset( $args['tax_query'] ) && $this->has_plathix_tax_query( $args['tax_query'] ) ) {
			$args['lang'] = 'all';
		}

		return $args;
	}

	/**
	 * Explicit-exclude Plathix taxonomies from Polylang's language-filtered allowlist
	 * ([internal]). Unconditional: $hide is ignored — folders stay shared regardless of
	 * whether Polylang is currently rendering its settings screen or running normally.
	 *
	 * @param array<string, string> $taxonomies
	 * @return array<string, string>
	 */
	public function exclude_plathix_taxonomies(array $taxonomies, bool $hide): array {
		foreach ( $taxonomies as $slug => $value ) {
			if ( $this->is_plathix_taxonomy( (string) $slug ) ) {
				unset( $taxonomies[ $slug ] );
			}
		}

		return $taxonomies;
	}

	private function is_plathix_taxonomy(string $taxonomy): bool {
		return $taxonomy === PLATHIX_TAXONOMY
			|| str_starts_with( $taxonomy, PLATHIX_TAX_PREFIX );
	}

	/**
	 * @param array<int|string, mixed> $tax_query
	 */
	private function has_plathix_tax_query(array $tax_query): bool {
		foreach ( $tax_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( isset( $clause['taxonomy'] ) && $this->is_plathix_taxonomy( (string) $clause['taxonomy'] ) ) {
				return true;
			}
		}

		return false;
	}
}
