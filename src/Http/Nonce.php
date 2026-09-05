<?php

declare(strict_types=1);

namespace Plathix\Http;

final class Nonce
{
	private const ACTION = 'plathix-nonce';

	public static function create(): string {
		return wp_create_nonce( self::ACTION );
	}

	public static function verify_or_die(): void {
		$nonce = sanitize_text_field( (string) wp_unslash( $_REQUEST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_send_json_error( [ 'code' => 'invalid_nonce' ], 403 );
		}
	}

	public static function verify(string $nonce): bool {
		return (bool) wp_verify_nonce( $nonce, self::ACTION );
	}
}
