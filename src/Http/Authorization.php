<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

final class Authorization
{

	private static array $cap_map = [
		'attachment' => [
			'view' => AccessLevel::View,
			'assign' => AccessLevel::Upload,
			'manage' => AccessLevel::Full,
		],
		'_cpt' => [
			'view' => AccessLevel::View,
			'assign' => AccessLevel::Upload,
			'manage' => AccessLevel::Full,
		],
	];

	public static function authorize(string $operation, string $post_type): bool {
		$post_type = sanitize_key( $post_type );

		if ( ! apply_filters( 'plathix/rest/post_type_allowed', true, $post_type ) ) {
			return false;
		}

		return self::capability( $operation, $post_type );
	}

	public static function capability(string $operation, string $post_type): bool {
		[ $wp_cap, $minLevel ] = self::capEntry( $operation, sanitize_key( $post_type ) );
		$user_level             = AccessResolver::forCurrentUser();

		return current_user_can( $wp_cap )
			&& $user_level !== AccessLevel::None
			&& $user_level->satisfies( $minLevel );
	}

	/**
	 * @return array{0: string, 1: AccessLevel}
	 */

	public static function capEntry(string $operation, string $post_type): array {
		$map_key = match ( true ) {
			$post_type === '' || $post_type === 'attachment' => 'attachment',
			isset( self::$cap_map[ $post_type ] ) => $post_type,
			default => '_cpt',
		};

		// $user_level !== AccessLevel::None).
		if ( ! isset( self::$cap_map[ $map_key ][ $operation ] ) ) {
			return [ 'do_not_allow', AccessLevel::None ];
		}

		$minLevel = self::$cap_map[ $map_key ][ $operation ];

		return [ $minLevel->resolveCap( $post_type ), $minLevel ];
	}
}
