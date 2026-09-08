<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderTreeService;

class RestRouteRegistry
{
	/**
	 * @param non-falsy-string $namespace
	 * @param RestRoutePermissions|null $permissions
	 */

	public function register(string $namespace, RestRouteHandlers $handlers, ?RestRoutePermissions $permissions = null): void {
		foreach ( $this->routeDefinitions( $handlers, $permissions ) as $route ) {
			register_rest_route( $namespace, $route['path'], $route['definition'] );
		}
	}

	/**
	 * Returns all route definitions without registering them.
	 * Allows testing the route structure without a WP environment.
	 *
	 * @return non-empty-list<array<string, mixed>>
	 */
	public function routeDefinitions(RestRouteHandlers $handlers, ?RestRoutePermissions $permissions = null): array {
		$permissions ??= $handlers instanceof RestRoutePermissions
			? $handlers
			: throw new \InvalidArgumentException( 'RestRouteRegistry: handlers do not implement RestRoutePermissions and no explicit permissions given.' );
		return [
			$this->routeFolders( $handlers, $permissions ),
			$this->routeFolderById( $handlers, $permissions ),
			$this->routeFoldersBatchCreate( $handlers, $permissions ),
			$this->routeFoldersBatchDelete( $handlers, $permissions ),
			$this->routeFoldersBatchUpdate( $handlers, $permissions ),
			$this->routeFoldersReorderTree( $handlers, $permissions ),
			$this->routeFoldersRecount( $handlers, $permissions ),
			$this->routeFolderRestore( $handlers, $permissions ),
			$this->routeFoldersTrashed( $handlers, $permissions ),
			$this->routeFolderPurge( $handlers, $permissions ),
			$this->routeFolderItems( $handlers, $permissions ),
			$this->routeItems( $handlers, $permissions ),
			$this->routePreferences( $handlers, $permissions ),
			$this->routeMediaBulkTrash( $handlers, $permissions ),
			$this->routeMediaBulkRestore( $handlers, $permissions ),
		];
	}

	/** @return array<string, mixed> */
	private static function postTypeArg(): array {
		return [
			'type'              => 'string',
			'default'           => 'attachment',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => static fn (mixed $value): bool => post_type_exists( (string) $value ) || (string) $value === 'attachment',
		];
	}

	/** @return array<string, mixed> */
	private static function positiveIdArg(): array {
		return [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFolders(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'getFolders' ],
					'permission_callback' => [ $permissions, 'canView' ],
					'args'                => [
						'post_type' => self::postTypeArg(),
						'search' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'ids' => [
							'required'          => false,
							'sanitize_callback' => [ $handlers, 'sanitizeIdsParam' ],
						],
						'parent_id' => [
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						],
						'fields' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'createFolder' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'name' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static fn (mixed $value): bool => is_string( $value ) && trim( $value ) !== '' && strlen( $value ) <= 200,
						],
						'parent_id' => [
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFolderById(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'getFolder' ],
					'permission_callback' => [ $permissions, 'canView' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'post_type' => self::postTypeArg(),
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'updateFolder' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'name'      => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
						'parent_id' => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'position'  => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'color'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_hex_color' ],
						'post_type' => self::postTypeArg(),
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'deleteFolder' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'id'          => self::positiveIdArg(),
						'post_type'   => self::postTypeArg(),
						'on_children' => [ 'type' => 'string', 'default' => FolderTreeService::DEFAULT_ON_CHILDREN, 'enum' => [ 'reattach', 'delete' ] ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersBatchCreate(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-create',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batchCreateFolders' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersBatchDelete(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-delete',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batchDeleteFolders' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'ids'         => [ 'required' => true, 'sanitize_callback' => [ $handlers, 'sanitizeIdsParam' ], 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'on_children' => [ 'type' => 'string', 'default' => FolderTreeService::DEFAULT_ON_CHILDREN, 'enum' => [ 'reattach', 'delete' ] ],
						'post_type'   => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersBatchUpdate(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-update',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batchUpdateFolders' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersReorderTree(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/reorder-tree',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'reorderTree' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersRecount(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/recount',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'recountFolders' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFolderItems(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/items',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'getFolderItems' ],
					'permission_callback' => [ $permissions, 'canView' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'post_type' => self::postTypeArg(),
						'paged'     => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'per_page'  => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'fields'    => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'moveItems' ],
					'permission_callback' => [ $permissions, 'canEdit' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'item_ids'  => [ 'required' => false, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) ],
						'ids'       => [ 'required' => false, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) ],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeItems(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/items',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'unassignItems' ],
					'permission_callback' => [ $permissions, 'canEdit' ],
					'args'                => [
						'item_ids'  => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && $v !== [] ],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}


	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routePreferences(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/preferences',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'updatePreferences' ],
					'permission_callback' => [ $permissions, 'canView' ],
					'args'                => [
						'post_type'      => self::postTypeArg(),
						'open_folder_id' => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeMediaBulkTrash(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/media/bulk-trash',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'bulkTrashMedia' ],
					'permission_callback' => [ $permissions, 'canEdit' ],
					'args'                => [
						'ids' => [
							'required'          => true,
							'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && count( $v ) > 0,
							'sanitize_callback' => [ $handlers, 'sanitizeIdsParam' ],
						],
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFolderRestore(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/restore',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'restoreFolder' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFolderPurge(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/purge',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'purgeFolder' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'id'        => self::positiveIdArg(),
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeFoldersTrashed(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/trashed',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'trashedFolders' ],
					'permission_callback' => [ $permissions, 'canManage' ],
					'args'                => [
						'post_type' => self::postTypeArg(),
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function routeMediaBulkRestore(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/media/bulk-restore',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'bulkRestoreMedia' ],
					'permission_callback' => [ $permissions, 'canEdit' ],
					'args'                => [
						'ids' => [
							'required'          => true,
							'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && count( $v ) > 0,
							'sanitize_callback' => [ $handlers, 'sanitizeIdsParam' ],
						],
						'target_folder_id' => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
						'post_type'        => self::postTypeArg(),
					],
				],
			],
		];
	}
}
