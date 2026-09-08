<?php

declare(strict_types=1);

namespace Plathix\Modules\Favorites;

use Plathix\Helpers\Sanitize;

final class FavoritesController
{
	public function __construct(
		private readonly \Closure $get_user_id,
		private readonly \Closure $setFavorites,
	) {
	}

	public function updateFavorites(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{

		if ( (bool) apply_filters( 'plathix/infrastructure/service_token_active', false ) ) {
			return new \WP_Error(
				'plathix_service_token_forbidden',
				__( 'Service tokens cannot modify personal favorites.', 'plathix' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! $request->has_param( 'favorites' ) ) {
			return new \WP_REST_Response(['success' => true]);
		}

		$post_type = (string) $request->get_param('post_type');
		$user_id   = ($this->get_user_id)();

		$ids = Sanitize::ids($request->get_param('favorites'));

		($this->setFavorites)($user_id, $ids, $post_type);

		return new \WP_REST_Response(['success' => true]);
	}
}
