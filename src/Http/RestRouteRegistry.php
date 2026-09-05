<?php

declare(strict_types=1);

namespace Plathix\Http;

class RestRouteRegistry
{
	/**
	 * @param non-falsy-string $namespace
	 * @param RestRoutePermissions|null $permissions null → $handlers обязан сам реализовывать
	 *        RestRoutePermissions (Free-путь: RestController — оба контракта). PRO передаёт
	 *        Free-handlers + СВОЙ permissions (CTAN-102, две роли — paradigm-skeptic р.4).
	 */
	public function register(string $namespace, RestRouteHandlers $handlers, ?RestRoutePermissions $permissions = null): void {
		foreach ( $this->route_definitions( $handlers, $permissions ) as $route ) {
			register_rest_route( $namespace, $route['path'], $route['definition'] );
		}
	}

	/**
	 * Returns all route definitions without registering them.
	 * Allows testing the route structure without a WP environment.
	 *
	 * @return non-empty-list<array<string, mixed>>
	 */
	public function route_definitions(RestRouteHandlers $handlers, ?RestRoutePermissions $permissions = null): array {
		$permissions ??= $handlers instanceof RestRoutePermissions
			? $handlers
			: throw new \InvalidArgumentException( 'RestRouteRegistry: handlers do not implement RestRoutePermissions and no explicit permissions given.' );
		return [
			$this->route_folders( $handlers, $permissions ),
			$this->route_folder_by_id( $handlers, $permissions ),
			$this->route_folders_batch_create( $handlers, $permissions ),
			$this->route_folders_batch_delete( $handlers, $permissions ),
			$this->route_folders_batch_update( $handlers, $permissions ),
			$this->route_folders_reorder_tree( $handlers, $permissions ),
			$this->route_folders_recount( $handlers, $permissions ),
			$this->route_folder_restore( $handlers, $permissions ),
			$this->route_folders_trashed( $handlers, $permissions ),
			$this->route_folder_purge( $handlers, $permissions ),
			$this->route_folder_items( $handlers, $permissions ),
			$this->route_items( $handlers, $permissions ),
			$this->route_preferences( $handlers, $permissions ),
			$this->route_media_bulk_trash( $handlers, $permissions ),
			$this->route_media_bulk_restore( $handlers, $permissions ),
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'get_folders' ],
					'permission_callback' => [ $permissions, 'can_view' ],
					'args'                => [
						'post_type' => [
							'type'              => 'string',
							'default'           => 'attachment',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static fn (mixed $value): bool => post_type_exists( (string) $value ) || (string) $value === 'attachment',
						],
						'search' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'ids' => [
							'required'          => false,
							'sanitize_callback' => [ $handlers, 'sanitize_ids_param' ],
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
					'callback'            => [ $handlers, 'create_folder' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
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
						'post_type' => [
							'type'              => 'string',
							'default'           => 'attachment',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static fn (mixed $value): bool => post_type_exists( (string) $value ) || (string) $value === 'attachment',
						],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folder_by_id(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'get_folder' ],
					'permission_callback' => [ $permissions, 'can_view' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'update_folder' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'name'      => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
						'parent_id' => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'position'  => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'color'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_hex_color' ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'delete_folder' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'id'          => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type'   => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
						'on_children' => [ 'type' => 'string', 'default' => 'delete', 'enum' => [ 'reattach', 'delete' ] ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_batch_create(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-create',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batch_create_folders' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_batch_delete(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-delete',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batch_delete_folders' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'ids'         => [ 'required' => true, 'sanitize_callback' => [ $handlers, 'sanitize_ids_param' ], 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'on_children' => [ 'type' => 'string', 'default' => 'delete', 'enum' => [ 'reattach', 'delete' ] ],
						'post_type'   => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_batch_update(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/batch-update',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'batch_update_folders' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_reorder_tree(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/reorder-tree',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'reorder_tree' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'items'     => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) && $value !== [] ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_recount(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/recount',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'recount_folders' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folder_items(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/items',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'get_folder_items' ],
					'permission_callback' => [ $permissions, 'can_view' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
						'paged'     => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'per_page'  => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
						'fields'    => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'move_items' ],
					'permission_callback' => [ $permissions, 'can_edit' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'item_ids'  => [ 'required' => false, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) ],
						'ids'       => [ 'required' => false, 'validate_callback' => static fn (mixed $value): bool => is_array( $value ) ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_items(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/items',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'unassign_items' ],
					'permission_callback' => [ $permissions, 'can_edit' ],
					'args'                => [
						'item_ids'  => [ 'type' => 'array', 'required' => true, 'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && $v !== [] ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	// Маршрут /folders/{id}/size регистрируется PRO-модулем FolderInfo через rest_api_init.

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_preferences(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/preferences',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $handlers, 'update_preferences' ],
					'permission_callback' => [ $permissions, 'can_view' ],
					'args'                => [
						'post_type'      => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
						'open_folder_id' => [ 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_media_bulk_trash(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/media/bulk-trash',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'bulk_trash_media' ],
					'permission_callback' => [ $permissions, 'can_edit' ],
					'args'                => [
						'ids' => [
							'required'          => true,
							'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && count( $v ) > 0,
							'sanitize_callback' => [ $handlers, 'sanitize_ids_param' ],
						],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folder_restore(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/restore',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'restore_folder' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folder_purge(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/(?P<id>\d+)/purge',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $handlers, 'purge_folder' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_folders_trashed(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/folders/trashed',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $handlers, 'trashed_folders' ],
					'permission_callback' => [ $permissions, 'can_manage' ],
					'args'                => [
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}

	/** @return array{path: string, definition: non-empty-list<array<string, mixed>>} */
	private function route_media_bulk_restore(RestRouteHandlers $handlers, RestRoutePermissions $permissions): array {
		return [
			'path'       => '/media/bulk-restore',
			'definition' => [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $handlers, 'bulk_restore_media' ],
					'permission_callback' => [ $permissions, 'can_edit' ],
					'args'                => [
						'ids' => [
							'required'          => true,
							'validate_callback' => static fn (mixed $v): bool => is_array( $v ) && count( $v ) > 0,
							'sanitize_callback' => [ $handlers, 'sanitize_ids_param' ],
						],
						'target_folder_id' => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
						'post_type'        => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			],
		];
	}
}
