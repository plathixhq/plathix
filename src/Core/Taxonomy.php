<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class Taxonomy
{
	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->addAction('init', $this, 'register');
	}

	public function register(): void {
		self::registerAll();
		self::ensureSystemTerms();

		if ( 1 === (int) get_option( 'plathix_boot_recovered_lazily', 0 ) ) {
			delete_option( 'plathix_boot_recovered_lazily' );
		}
	}

	private static bool $ready = false;

	/**
	 * @param bool $lazy_recovery
	 */

	public static function ensureReady(bool $lazy_recovery = false): void {
		if ( self::$ready ) {
			return;
		}

		$needed_registration = false;
		foreach ( self::getEnabledTaxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$needed_registration = true;
				break;
			}
		}

		if ( $needed_registration ) {
			self::registerAll();
		}

		self::ensureSystemTerms();

		do_action( 'plathix/taxonomy/ensureSystemTerms' );

		self::$ready = true;

		if ( $lazy_recovery && $needed_registration ) {

			\Plathix\Infrastructure\Logger::warning(
				'system_terms_recovered_lazily',
				[ 'reason' => 'init_hook_did_not_run', 'hint' => 'third-party plugin likely fataled on init before Plathix' ]
			);
			update_option( 'plathix_boot_recovered_lazily', 1, false );
		}
	}

	public static function resetReadyFlag(): void {
		self::$ready = false;
	}

	public static function registerAll(): void {
		foreach ( self::getEnabledPostTypes() as $post_type ) {
			$taxonomy = self::taxonomyForPostType($post_type);
			if ( ! self::isValidTaxonomySlug($taxonomy) ) {
				continue;
			}

			register_taxonomy(
				$taxonomy,
				[ $post_type ],
				[
					'labels' => [
						'name' => __('Folders', 'plathix'),
						'singular_name' => __('Folder', 'plathix'),
					],
					'public' => false,
					'show_ui' => false,
					'show_in_menu' => false,
					'show_in_nav_menus' => false,
					'show_tagcloud' => false,
					'hierarchical' => true,
					'rewrite' => false,
					'query_var' => false,
					'show_in_rest' => false,
				]
			);
		}
	}

	public static function ensureSystemTerms(): void {
		foreach ( self::getEnabledTaxonomies() as $taxonomy ) {
			FolderRepository::ensureSystemTerms($taxonomy);
		}
	}

	/**
	 * @return array<string>
	 */

	public static function getEnabledPostTypes(): array {

		return [ 'attachment' ];
	}

	/** @return array<int, string> */
	public static function getEnabledTaxonomies(): array {
		return array_values(
			array_filter(
				array_map([ self::class, 'taxonomyForPostType' ], self::getEnabledPostTypes()),
				[ self::class, 'isValidTaxonomySlug' ]
			)
		);
	}

	public static function taxonomyForPostType(string $post_type): string {
		return TaxonomyResolver::fromPostType(sanitize_key($post_type));
	}

	public static function postTypeForTaxonomy(string $taxonomy): string {
		$taxonomy = sanitize_key($taxonomy);
		if ( PLATHIX_TAXONOMY === $taxonomy ) {
			return 'attachment';
		}

		if ( str_starts_with($taxonomy, PLATHIX_TAX_PREFIX) ) {
			return sanitize_key(substr($taxonomy, strlen(PLATHIX_TAX_PREFIX)));
		}

		return 'attachment';
	}

	private static function isValidTaxonomySlug(string $taxonomy): bool {
		if ( strlen($taxonomy) <= 32 ) {
			return true;
		}

		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- trigger_error is a debug log gated behind WP_DEBUG, not user-facing output and not shipped debug code
			trigger_error(
				sprintf(
					'plathix: taxonomy slug "%s" (%d chars) exceeds WP limit of 32 and will be skipped at runtime.',
					$taxonomy,
					strlen($taxonomy)
				),
				E_USER_WARNING
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		return false;
	}
}
