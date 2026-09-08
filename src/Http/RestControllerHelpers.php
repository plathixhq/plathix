<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\TaxonomyResolver;
use Plathix\Helpers\Sanitize;

trait RestControllerHelpers
{
	private function runOptionalOverride(?\Closure $override, callable $fallback, mixed ...$args): mixed {
		if ( $override instanceof \Closure ) {
			return $override( ...$args );
		}

		return $fallback( ...$args );
	}

	private function requestTaxonomy(\WP_REST_Request $request): string {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );

		return TaxonomyResolver::fromPostTypeOrFallback( $post_type );
	}

	private function errorResponse(\WP_Error $error, int $fallback_status): \WP_REST_Response {
		$data   = method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : $fallback_status;

		return new \WP_REST_Response( [ 'message' => $error->get_error_message(), 'code' => $error->get_error_code() ], $status );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function recordAudit(string $action, array $payload, ?\Closure $runner = null): void {
		if ( $runner instanceof \Closure ) {
			$runner( $action, $payload );
			return;
		}

		do_action( 'plathix/audit/record', $action, $payload );
	}

	public function sanitizeIdsParam(mixed $value): array {
		return Sanitize::idsFromCsvOrArray( $value );
	}

	public static function requestScalar(mixed $value): string {
		return Sanitize::toScalarString( $value );
	}
}
