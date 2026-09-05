<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;

final class StructureExporter
{
	/** @var \Closure(string): \WP_Term[] */
	private \Closure $terms_provider;
	/** @var \Closure(int, string): mixed */
	private \Closure $meta_provider;

	public function __construct(
		?callable $terms_provider = null,
		?callable $meta_provider = null
	) {
		$this->terms_provider = \Closure::fromCallable(
			$terms_provider ?? static function (string $taxonomy): array {
				return ( new FolderRepository() )->get_all( $taxonomy );
			}
		);
		$this->meta_provider = \Closure::fromCallable(
			$meta_provider ?? static function (int $term_id, string $key): mixed {
				return ( new FolderRepository() )->get_meta( $term_id, $key );
			}
		);
	}

	/** @return string[] */
	public static function available_taxonomies(): array {
		/** @var array<int, string> $merged */
		$merged = array_merge( [ (string) PLATHIX_TAXONOMY ], Taxonomy::get_enabled_taxonomies() );
		return array_values( array_unique( $merged ) );
	}

	/**
	 * @param string[]|null $taxonomies null = all available
	 * @return array<string, mixed>
	 */
	public function export_payload(?array $taxonomies = null): array {
		return [
			'plugin'       => 'plathix',
			'version'      => PLATHIX_VERSION,
			'generated_at' => gmdate( 'c' ),
			'site_url'     => home_url( '/' ),
			'taxonomies'   => $this->export_taxonomies( $taxonomies ),
		];
	}

	/**
	 * @param string[]|null $taxonomies null = all available
	 */
	public function export_json(?array $taxonomies = null): string|false {
		return wp_json_encode(
			$this->export_payload( $taxonomies ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * @param string[]|null $filter null = all available
	 * @return array<int, array<string, mixed>>
	 */
	private function export_taxonomies(?array $filter = null): array {
		$taxonomies = self::available_taxonomies();

		if ( $filter !== null ) {
			$taxonomies = array_values( array_intersect( $taxonomies, $filter ) );
		}

		$result = [];

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = [];
			foreach ( ( $this->terms_provider )( $taxonomy ) as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$terms[] = [
					'id'       => (int) $term->term_id,
					'name'     => (string) $term->name,
					'slug'     => (string) $term->slug,
					'parent'   => (int) $term->parent,
					'count'    => (int) $term->count,
					'position' => (int) ( $this->meta_provider )( (int) $term->term_id, PLATHIX_TERM_POSITION ),
					'color'    => (string) ( $this->meta_provider )( (int) $term->term_id, PLATHIX_TERM_COLOR ),
				];
			}

			$result[] = [
				'taxonomy'  => $taxonomy,
				'post_type' => Taxonomy::post_type_for_taxonomy( $taxonomy ),
				'folders'   => $terms,
			];
		}

		return $result;
	}
}
