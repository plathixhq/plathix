<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\TaxonomyResolver;

/**
 * Общие helpers для RestController и feature controllers.
 * Содержит только утилиты без состояния — не хранит свойств.
 */
trait RestControllerHelpers
{
	private function run_optional_override(?\Closure $override, callable $fallback, mixed ...$args): mixed {
		if ( $override instanceof \Closure ) {
			return $override( ...$args );
		}

		return $fallback( ...$args );
	}

	private function request_taxonomy(\WP_REST_Request $request): string {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		$taxonomy  = TaxonomyResolver::fromPostType( $post_type );

		return taxonomy_exists( $taxonomy ) ? $taxonomy : PLATHIX_TAXONOMY;
	}

	private function error_response(\WP_Error $error, int $fallback_status): \WP_REST_Response {
		$data   = method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : $fallback_status;

		return new \WP_REST_Response( [ 'message' => $error->get_error_message(), 'code' => $error->get_error_code() ], $status );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function record_audit(string $action, array $payload, ?\Closure $runner = null): void {
		if ( $runner instanceof \Closure ) {
			$runner( $action, $payload );
			return;
		}

		do_action( 'plathix/audit/record', $action, $payload );
	}

	/**
	 * @return list<int> Список неотрицательных ID (absint + фильтрация нулей).
	 */
	public function sanitize_ids_param(mixed $value): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', $value ) ?: [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	/**
	 * Приводит значение из REST-параметра к скаляру перед `(string)`-кастом. Клиент
	 * может прислать параметр как массив (`?post_type[]=x`) — без этой проверки PHP
	 * кидает `Array to string conversion` warning ([internal]; тот же паттерн, что
	 * `Plathix\Core\FolderQuery::request_scalar()` уже закрывает для `$_GET`/REST-параметров
	 * внутри `FolderQuery`, [internal]/#671). `public`, а не `private`: вызывается из
	 * `RestController` (через trait), `Rest::can_edit()` и `Modules\Favorites\Module`
	 * closure напрямую как `RestControllerHelpers::request_scalar()`.
	 */
	public static function request_scalar(mixed $value): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
