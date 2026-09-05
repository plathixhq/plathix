<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\OpenFolderResolver;
use Plathix\User\Preferences;

final class PreferencesController
{
	public function update_preferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		// [internal]: открытая папка — личное UI-состояние человека, не имеет смысла для
		// машинного service-токена; закрыто независимо от access_level токена.
		if ( (bool) apply_filters( 'plathix/infrastructure/service_token_active', false ) ) {
			return new \WP_Error(
				'plathix_service_token_forbidden',
				__( 'Service tokens cannot modify personal preferences.', 'plathix' ),
				[ 'status' => 403 ]
			);
		}

		$post_type = (string) $request->get_param( 'post_type' );
		$user_id   = get_current_user_id();

		if ( $request->has_param( 'open_folder_id' ) ) {
			// [internal]: нормализуем невалидный/cross-taxonomy id → 0 (ROOT) перед записью,
			// чтобы битый указатель не персистился в user_meta.
			$folder_id = OpenFolderResolver::normalize( absint( $request->get_param( 'open_folder_id' ) ), $post_type );
			Preferences::set_open_folder_id( $user_id, $folder_id, $post_type );
		}

		return new \WP_REST_Response( [ 'success' => true ] );
	}
}
