<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

final class AjaxGuard
{
	/**
	 * @param AccessLevel $min
	 * @param string|null $cap
	 * @param string|null $post_type
	 */

	public static function require(AccessLevel $min, ?string $cap = null, ?string $post_type = null): void {
		Nonce::verifyOrDie();

		if ( null !== $post_type ) {

			$effective_type = '' === $post_type ? 'attachment' : $post_type;
			if ( 'attachment' !== $effective_type ) {
				wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
			}
		}

		$user_level = AccessResolver::forCurrentUser();
		$allowed    = $user_level->satisfies( $min );

		if ( ( null === $cap || '' === $cap ) && null !== $post_type ) {
			$cap = $min->resolveCap( $post_type );
		}

		if ( null === $post_type && ( null === $cap || '' === $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}

		if ( ! $allowed || ! current_user_can( (string) $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}
	}

	/**
	 * @param AccessLevel $min
	 * @param string      $cap
	 */

	public static function requireCap(AccessLevel $min, string $cap): void {
		$allowed = AccessResolver::forCurrentUser()->satisfies( $min );

		if ( ! $allowed || ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}
	}
}
