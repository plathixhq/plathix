<?php

declare(strict_types=1);

namespace Plathix\Modules\Multilingual;

use Plathix\Core\MultilingualCompat;
use Plathix\Core\TaxonomyResolver;
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
		$this->loader->addFilter( 'wpml_is_translated_taxonomy', $this, 'isTranslatedTaxonomy', 10, 2 );
		$this->loader->addFilter( 'pll_is_translated_taxonomy', $this, 'isTranslatedTaxonomyPll', 10, 2 );

		$this->loader->addFilter( 'pll_get_taxonomies', $this, 'excludePlathixTaxonomies', 10, 2 );

		// Suppress language filter when Plathix runs its own queries.
		$this->loader->addAction( 'pre_get_posts', $this, 'suppressLanguageFilter', 1 );
		$this->loader->addFilter( 'ajax_query_attachments_args', $this, 'addLangAllAjax', 1 );
	}

	/**
	 * Tell WPML that all plathix taxonomies are not translatable.
	 *
	 * @param bool   $is_translated  Current value.
	 * @param string $taxonomy       Taxonomy slug.
	 */
	public function isTranslatedTaxonomy(bool $is_translated, string $taxonomy): bool {
		if ( $this->isPlathixTaxonomy( $taxonomy ) ) {
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
	public function isTranslatedTaxonomyPll(bool $is_translated, string $taxonomy): bool {
		return $this->isTranslatedTaxonomy( $is_translated, $taxonomy );
	}

	/**
	 * Remove WPML/Polylang language constraint from WP_Query when it is
	 * one of our internal queries (uncategorized count).
	 */
	public function suppressLanguageFilter(\WP_Query $query): void {
		if ( ! $query->get( 'plathix_suppress_lang' ) ) {
			return;
		}

		MultilingualCompat::suppressForQuery( $query );
	}

	/**
	 * For the media grid AJAX query, add lang=all so Polylang/WPML
	 * return attachments from all languages when filtering by folder.
	 *
	 * @param  array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function addLangAllAjax(array $args): array {

		// isPolylangActiveWithoutLanguages(): "38 vs 0 results for the same

		if ( isset( $args['tax_query'] ) && $this->hasPlathixTaxQuery( $args['tax_query'] ) ) {
			return MultilingualCompat::suppressForArgs( $args );
		}

		return $args;
	}

	/**
	 * @param array<string, string> $taxonomies
	 * @return array<string, string>
	 */

	public function excludePlathixTaxonomies(array $taxonomies, bool $hide): array {
		foreach ( $taxonomies as $slug => $value ) {
			if ( $this->isPlathixTaxonomy( (string) $slug ) ) {
				unset( $taxonomies[ $slug ] );
			}
		}

		return $taxonomies;
	}

	private function isPlathixTaxonomy(string $taxonomy): bool {
		return TaxonomyResolver::isPlathixTaxonomy( $taxonomy );
	}

	/**
	 * @param array<int|string, mixed> $tax_query
	 */
	private function hasPlathixTaxQuery(array $tax_query): bool {
		foreach ( $tax_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( isset( $clause['taxonomy'] ) && $this->isPlathixTaxonomy( (string) $clause['taxonomy'] ) ) {
				return true;
			}
		}

		return false;
	}
}
