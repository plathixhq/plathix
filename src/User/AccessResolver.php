<?php

declare(strict_types=1);

namespace Plathix\User;

final class AccessResolver
{
	public function __construct(
		private readonly int $user_id = 0
	) {
	}

	public static function forCurrentUser(): AccessLevel {
		return ( new self( get_current_user_id() ) )->resolve();
	}

	public static function currentUserIsFullAdmin(): bool {
		return current_user_can( 'manage_options' ) && self::forCurrentUser() === AccessLevel::Full;
	}

	public function resolve(): AccessLevel {
		if ( $this->user_id <= 0 ) {
			return $this->filterLevel( AccessLevel::None );
		}

		if ( user_can( $this->user_id, 'manage_options' ) ) {
			return $this->filterLevel( AccessLevel::Full );
		}

		if ( user_can( $this->user_id, 'upload_files' ) ) {
			return $this->filterLevel( AccessLevel::Upload );
		}

		return $this->filterLevel( AccessLevel::None );
	}

	private function filterLevel(AccessLevel $level): AccessLevel {
		$value = apply_filters( 'plathix/user/access_level', $level->value, $this->user_id );

		return AccessLevel::tryFrom( (string) $value ) ?? $level;
	}
}
