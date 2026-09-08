<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Http\AjaxGuard;
use Plathix\Http\Nonce;
use Plathix\User\AccessLevel;

final class ListScreenAuthorizer
{

	private const ALLOWED_SCREEN_BASES = [ 'upload' ];

	/**
	 * @param array<string, mixed> $request
	 */

	public function authorize(array $request): void {
		Nonce::verifyOrDie();

		if ( ! in_array( $request['screen_base'], self::ALLOWED_SCREEN_BASES, true ) ) {
			$this->jsonError( 'Invalid screen_base.', 400 );
			return;
		}

		AjaxGuard::require( AccessLevel::View, null, (string) $request['post_type'] );
	}

	private function jsonError(string $message, int $status = 400): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}
}
