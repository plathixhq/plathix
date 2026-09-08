<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\User\AccessResolver;

final class ReplaceAuthorization
{
	/**
	 * @param mixed $context
	 * @return array{mode:string,user_id:int}
	 */

	public function normalize(mixed $context): array {
		$context = is_array( $context ) ? $context : [];
		$mode = sanitize_key( (string) ( $context['mode'] ?? 'wp_user' ) );

		return [
			'mode'    => $mode !== '' ? $mode : 'wp_user',
			'user_id' => max( 0, (int) ( $context['user_id'] ?? 0 ) ),
		];
	}

	/**
	 * @param array{mode:string,user_id:int} $actor_context
	 */

	public function canReplace(array $actor_context, int $attachment_id): bool {
		if ( $actor_context['mode'] === 'system_cli' ) {
			return true;
		}

		$user_id = $actor_context['user_id'];
		if ( $user_id <= 0 ) {
			return false;
		}

		$level = ( new AccessResolver( $user_id ) )->resolve();

		return $level->canUpload() && user_can( $user_id, 'edit_post', $attachment_id );
	}
}
