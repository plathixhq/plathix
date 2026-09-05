<?php

declare(strict_types=1);

namespace Plathix\Http;

final class RestAuditPayloadBuilders
{
	/**
	 * @return array<string, mixed>
	 */
	public static function from_request(\WP_REST_Request $request): array {
		return [
			'userId'     => absint( $request->get_param( 'user_id' ) ),
			'objectType' => sanitize_key( (string) $request->get_param( 'object_type' ) ),
			'objectId'   => absint( $request->get_param( 'object_id' ) ),
			'targetType' => sanitize_key( (string) $request->get_param( 'target_type' ) ),
			'targetId'   => absint( $request->get_param( 'target_id' ) ),
			'itemsCount' => absint( $request->get_param( 'items_count' ) ),
			'summary'    => sanitize_text_field( (string) $request->get_param( 'summary' ) ),
			'context'    => self::normalize_context( $request->get_param( 'context' ) ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function normalize_context(mixed $context): array {
		if ( is_array( $context ) ) {
			return $context;
		}

		return $context === null || $context === '' ? [] : [ 'value' => $context ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function import_job(string $adapter, string $post_type, int $job_id): array {
		return [
			'objectType' => 'import',
			'objectId'   => $job_id,
			'summary'    => sprintf( 'Queued import adapter "%s"', $adapter ),
			'context'    => [
				'adapter'  => $adapter,
				'post_type' => $post_type,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function structure_export(string $post_type): array {
		return [
			'objectType' => 'export',
			'summary'    => 'Exported folder structure',
			'context'    => [
				'post_type' => $post_type,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function filters_from_request(\WP_REST_Request $request): array {
		return [
			'action'       => sanitize_key( (string) $request->get_param( 'action' ) ),
			'user_id'      => absint( $request->get_param( 'user_id' ) ),
			'object_type'  => sanitize_key( (string) $request->get_param( 'object_type' ) ),
			'object_id'    => absint( $request->get_param( 'object_id' ) ),
			'target_type'  => sanitize_key( (string) $request->get_param( 'target_type' ) ),
			'target_id'    => absint( $request->get_param( 'target_id' ) ),
			'created_from' => sanitize_text_field( (string) $request->get_param( 'created_from' ) ),
			'created_to'   => sanitize_text_field( (string) $request->get_param( 'created_to' ) ),
			'paged'        => absint( $request->get_param( 'paged' ) ) ?: 1,
			'per_page'     => absint( $request->get_param( 'per_page' ) ) ?: 50,
		];
	}

	public static function export_format_from_request(\WP_REST_Request $request): string {
		$format = sanitize_key( (string) ( $request->get_param( 'format' ) ?: 'json' ) );

		return in_array( $format, [ 'json', 'csv' ], true ) ? $format : '';
	}

	/**
	 * @param array<string, mixed> $result
	 * @param array<string, mixed> $filters
	 * @return array{items: list<mixed>, total: int, page: int, perPage: int}
	 */
	public static function entries_response(array $result, array $filters): array {
		return [
			'items'   => array_values( (array) ( $result['rows'] ?? [] ) ),
			'total'   => (int) ( $result['total'] ?? 0 ),
			'page'    => (int) ( $result['paged'] ?? $filters['paged'] ),
			'perPage' => (int) ( $result['per_page'] ?? $filters['per_page'] ),
		];
	}
}
