<?php

declare(strict_types=1);

namespace Plathix\Http;

final class RestFailureValues
{
	/**
	 * @return array{id:int,message:string}
	 */
	public static function invalid_folder(int $id = 0): array {
		return [ 'id' => $id, 'message' => __( 'Invalid folder ID.', 'plathix' ) ];
	}

	/**
	 * @return array{id:int,message:string}
	 */
	public static function missing_folder(int $id): array {
		return [ 'id' => $id, 'message' => __( 'Folder no longer exists.', 'plathix' ) ];
	}

	/**
	 * @return array{id:int,message:string}
	 */
	public static function delete_folder(int $id): array {
		return [ 'id' => $id, 'message' => __( 'Unable to delete folder.', 'plathix' ) ];
	}

	/**
	 * @return array{id:int,code:string,message:string}|array{id:int,code:string,message:string,applied:list<string>}
	 */
	public static function wp_error(int $id, \WP_Error $error): array {
		$result = [
			'id'      => $id,
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		];

		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['applied'] ) && is_array( $data['applied'] ) ) {
			$result['applied'] = array_values( $data['applied'] );
		}

		return $result;
	}

	/**
	 * @return array{name:string,message:string}
	 */
	public static function empty_folder_name(): array {
		return [
			'name'    => '',
			'message' => __( 'Folder name cannot be empty.', 'plathix' ),
		];
	}

	/**
	 * @return array{name:string,parentId:int,code:string,message:string}
	 */
	public static function batch_create(string $name, int $parent_id, \WP_Error $error): array {
		return [
			'name'     => $name,
			'parentId' => $parent_id,
			'code'     => (string) $error->get_error_code(),
			'message'  => $error->get_error_message(),
		];
	}

	/**
	 * @return array{id:int,name:string,parentId:int}
	 */
	public static function batch_created_row(int $id, string $name, int $parent_id): array {
		return [
			'id'       => $id,
			'name'     => $name,
			'parentId' => $parent_id,
		];
	}
}
