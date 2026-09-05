<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\Cache;

final class StructureImporter
{
	/** @var \Closure(string, int, string): (int|\WP_Error) */
	private \Closure $creator;

	/**
	 * @param callable(string $name, int $parent, string $taxonomy): (int|\WP_Error)|null $creator
	 */
	public function __construct(?callable $creator = null) {
		$this->creator = \Closure::fromCallable(
			$creator ?? static function (string $name, int $parent, string $taxonomy): int|\WP_Error {
				$cache      = Cache::make();
				$repository = new FolderRepository();
				$count      = new FolderCountService( $repository, $cache );
				$tree       = new FolderTreeService( $repository, $count );

				return $tree->create( $name, $parent, $taxonomy );
			}
		);
	}

	/**
	 * @param array<string, mixed> $payload Decoded JSON payload from StructureExporter.
	 * @param string[]|null $taxonomies Taxonomy slugs to import; null = all present in file.
	 * @return array{imported: int, skipped: int, errors: int, error_details: list<array{taxonomy: string, code: string, message: string}>}
	 */
	public function import(array $payload, ?array $taxonomies = null): array {
		$stats = [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => [] ];

		$entries = $payload['taxonomies'] ?? [];
		if ( ! is_array( $entries ) ) {
			return $stats;
		}

		foreach ( $entries as $entry ) {
			$taxonomy = sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) );

			if ( '' === $taxonomy ) {
				continue;
			}

			if ( $taxonomies !== null && ! in_array( $taxonomy, $taxonomies, true ) ) {
				$stats['skipped']++;
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				$stats['skipped']++;
				continue;
			}

			$folders = $entry['folders'] ?? [];
			if ( ! is_array( $folders ) ) {
				continue;
			}

			$result = $this->import_folders( $folders, $taxonomy );
			$stats['imported'] += $result['imported'];
			$stats['errors']   += $result['errors'];
			array_push( $stats['error_details'], ...$result['error_details'] );
		}

		return $stats;
	}

	/**
	 * @param array<int, array<string, mixed>> $folders Raw folder rows from JSON.
	 * @param string $taxonomy Target taxonomy slug.
	 * @return array{imported: int, errors: int, error_details: list<array{taxonomy: string, code: string, message: string}>}
	 */
	private function import_folders(array $folders, string $taxonomy): array {
		$stats = [ 'imported' => 0, 'errors' => 0, 'error_details' => [] ];

		// old_id => new_id map, 0 => 0 (root)
		$map     = [ 0 => 0 ];
		$pending = $folders;
		$passes  = count( $pending ) + 1;
		$pass    = 0;

		while ( $pending !== [] && $pass++ < $passes ) {
			$still_pending = [];

			foreach ( $pending as $folder ) {
				$old_id    = (int) ( $folder['id'] ?? 0 );
				$old_parent = (int) ( $folder['parent'] ?? 0 );
				$name      = trim( (string) ( $folder['name'] ?? '' ) );

				if ( $name === '' ) {
					$stats['errors']++;
					$stats['error_details'][] = [
						'taxonomy' => $taxonomy,
						'code'     => 'empty_name',
						'message'  => sprintf( 'Folder row (old id %d) has an empty name.', $old_id ),
					];
					continue;
				}

				if ( ! array_key_exists( $old_parent, $map ) ) {
					$still_pending[] = $folder;
					continue;
				}

				$new_parent = $map[ $old_parent ];
				$final_name = $this->resolve_name( $name, $new_parent, $taxonomy );
				$created    = ( $this->creator )( $final_name, $new_parent, $taxonomy );

				if ( is_wp_error( $created ) ) {
					$stats['errors']++;
					// Причина сохраняется ([internal]): раньше WP_Error от FolderTreeService::create()
					// молча отбрасывался, оставляя только инкремент счётчика без деталей.
					$stats['error_details'][] = [
						'taxonomy' => $taxonomy,
						'code'     => (string) $created->get_error_code(),
						'message'  => (string) $created->get_error_message(),
					];
					continue;
				}
				/** @var int $created Narrowed after is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */

				$map[ $old_id ] = (int) $created;
				$stats['imported']++;
			}

			$pending = $still_pending;
		}

		// Anything left couldn't be resolved (orphaned nodes) — not a WP_Error, a structural
		// cycle/missing-parent case; marked with a synthetic code, not a real WP_Error code.
		foreach ( $pending as $folder ) {
			$stats['error_details'][] = [
				'taxonomy' => $taxonomy,
				'code'     => 'orphaned_node',
				'message'  => sprintf(
					'Folder row (old id %d, parent %d) could not be resolved — parent never appeared in the import.',
					(int) ( $folder['id'] ?? 0 ),
					(int) ( $folder['parent'] ?? 0 )
				),
			];
		}
		$stats['errors'] += count( $pending );

		return $stats;
	}

	private function resolve_name(string $name, int $parent, string $taxonomy): string {
		$existing = get_terms( [
			'taxonomy'   => $taxonomy,
			'parent'     => $parent,
			'hide_empty' => false,
			'fields'     => 'names',
		] );

		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
			return $name;
		}

		if ( ! in_array( $name, $existing, true ) ) {
			return $name;
		}

		return $name . ' — imported';
	}
}
