<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\OpenFolderResolver;
use Plathix\User\Preferences;

final class PreferencesController
{
	public function updatePreferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {

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

			$folder_id = OpenFolderResolver::normalize( absint( $request->get_param( 'open_folder_id' ) ), $post_type );
			Preferences::setOpenFolderId( $user_id, $folder_id, $post_type );
		}

		return new \WP_REST_Response( [ 'success' => true ] );
	}
}
