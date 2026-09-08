<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderCountService;

final class FolderReadController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderCountService $folders,
	) {
	}

	public function getFolders(\WP_REST_Request $request): \WP_REST_Response {
		$taxonomy  = $this->requestTaxonomy( $request );
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$ids       = $this->sanitizeIdsParam( $request->get_param( 'ids' ) );
		$parent_id = $request->has_param( 'parent_id' ) ? absint( $request->get_param( 'parent_id' ) ) : null;

		$fields    = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					preg_split( '/\s*,\s*/', (string) $request->get_param( 'fields' ) ) ?: []
				)
			)
		);
		$has_field = static function (string $field) use ($fields): bool {
			return in_array( sanitize_key( $field ), $fields, true );
		};

		// Partial path: only direct children of a single parent, no search, no id filter.
		// FolderCountService::getChildren fetches only the needed subset — no full tree load.
		$use_partial = null !== $parent_id && $search === '' && $ids === [];
		if ( $use_partial ) {
			$folders = $this->folders->getChildren( $parent_id, $taxonomy );
			$payload = array_map(
				fn (\Plathix\Core\FolderDTO $folder): array => $this->projectFolder( $folder, $fields, $has_field, $folder->hasChildren ),
				$folders
			);
			return new \WP_REST_Response( [ 'folders' => $payload, 'taxonomy' => $taxonomy, 'fullTree' => false ] );
		}

		// Full-tree path: search, ids filter, or no parent_id specified.
		$folders = $this->folders->getAllCached( $taxonomy );
		$has_children_map = [];
		foreach ( $folders as $folder ) {
			$folder_parent_id = (int) $folder->parentId;
			if ( $folder_parent_id > 0 ) {
				$has_children_map[ $folder_parent_id ] = true;
			}
		}

		if ( $search !== '' ) {
			$folders = array_values(
				array_filter(
					$folders,
					static function (\Plathix\Core\FolderDTO $folder) use ($search): bool {
						return str_contains( strtolower( $folder->name ), strtolower( $search ) );
					}
				)
			);
		}

		if ( $ids !== [] ) {
			$index = array_fill_keys( array_map( 'strval', $ids ), true );
			$folders = array_values(
				array_filter(
					$folders,
					static function (\Plathix\Core\FolderDTO $folder) use ($index): bool {
						return isset( $index[ (string) $folder->id ] );
					}
				)
			);
		}

		$payload = array_map(
			fn (\Plathix\Core\FolderDTO $folder): array => $this->projectFolder(
				$folder,
				$fields,
				$has_field,
				! empty( $has_children_map[ (int) $folder->id ] )
			),
			$folders
		);

		return new \WP_REST_Response( [ 'folders' => $payload, 'taxonomy' => $taxonomy, 'fullTree' => $search === '' && $ids === [] ] );
	}

	public function getFolder(\WP_REST_Request $request, ?\Closure $loader_override = null): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->requestTaxonomy( $request );

		$folder = $this->runOptionalOverride(
			$loader_override,
			fn (): array => $this->loadSingleFolder( $id, $taxonomy ),
			$id,
			$taxonomy
		);

		if ( ! is_array( $folder ) || $folder === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 404 );
		}

		return new \WP_REST_Response( [ 'folder' => $folder, 'taxonomy' => $taxonomy ] );
	}

	public function getFolderItems(\WP_REST_Request $request, ?\Closure $loader_override = null): \WP_REST_Response {
		$folder_id = absint( $request->get_param( 'id' ) );
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		$taxonomy  = $this->requestTaxonomy( $request );
		$page      = max( 1, absint( $request->get_param( 'paged' ) ) ?: 1 );
		$per_page  = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 50 ) );
		$fields    = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					preg_split( '/\s*,\s*/', (string) $request->get_param( 'fields' ) ) ?: []
				)
			)
		);

		$result = $this->runOptionalOverride(
			$loader_override,
			fn (): array => $this->loadFolderItems( $folder_id, $post_type, $taxonomy, $page, $per_page, $fields ),
			$folder_id,
			$post_type,
			$taxonomy,
			$page,
			$per_page,
			$fields
		);

		return new \WP_REST_Response( RestFolderResponseBuilders::folderItems( $result, $taxonomy, $folder_id, $page, $per_page ) );
	}

	/**
	 * @param string[] $fields
	 * @param \Closure(string): bool $has_field
	 * @return array<string, mixed>
	 */

	private function projectFolder(\Plathix\Core\FolderDTO $folder, array $fields, \Closure $has_field, bool $has_children): array {
		$data = $folder->toArray();
		$data['hasChildren'] = $has_children;
		if ( array_key_exists( 'count', $data ) && $data['count'] === null ) {
			$data['count'] = 0;
		}

		if ( $fields === [] ) {
			return $data;
		}

		$subset = [];
		foreach ( $data as $key => $value ) {
			if ( $has_field( (string) $key ) ) {
				$subset[ $key ] = $value;
			}
		}

		return $subset;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadSingleFolder(int $id, string $taxonomy): array {
		$folders = $this->folders->getAllCached( $taxonomy );
		foreach ( $folders as $folder ) {
			$data = $folder instanceof \Plathix\Core\FolderDTO ? $folder->toArray() : (array) $folder;
			if ( (int) ( $data['id'] ?? 0 ) === $id ) {
				return $data;
			}
		}

		return [];
	}

	/**
	 * @param string[] $fields
	 * @return array{items: list<array<string, int|string>>, total: int, page: int, per_page: int}
	 */

	private function loadFolderItems(int $folder_id, string $post_type, string $taxonomy, int $page, int $per_page, array $fields): array {
		return ( new \Plathix\Core\FolderItemsLoader() )->load( $folder_id, $post_type, $taxonomy, $page, $per_page, $fields );
	}
}
