<?php

declare(strict_types=1);

namespace Plathix\Http;

final class Rest
{
	use RestControllerHelpers;

	public const NAMESPACE = 'plathix/' . RestController::API_VERSION;

	public static function canEdit(\WP_REST_Request $request): bool {
		return RestController::check( 'assign', self::requestScalar( $request->get_param( 'post_type' ) ) );
	}
}
