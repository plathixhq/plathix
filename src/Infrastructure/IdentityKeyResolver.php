<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class IdentityKeyResolver
{
	public static function resolve(int $user_id): string {
		return (string) apply_filters( 'plathix/infrastructure/current_identity_key', (string) $user_id, $user_id );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function matchesOwner(array $payload): bool {
		$payload_user_id = (int) ( $payload['user_id'] ?? 0 );

		return apply_filters(
			'plathix/infrastructure/resolve_owner_identity',
			self::resolve( $payload_user_id ),
			$payload_user_id,
			isset( $payload['created_by_token_id'] ) ? (string) $payload['created_by_token_id'] : null
		) === self::resolve( get_current_user_id() );
	}
}
