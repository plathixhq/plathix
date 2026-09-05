<?php

declare(strict_types=1);

namespace Plathix\Modules\Favorites;

final class FavoritesController
{
	public function __construct(
		private readonly \Closure $get_user_id,
		private readonly \Closure $set_favorites,
	) {
	}

	public function update_favorites(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		// [internal]: избранное — личное UI-состояние человека, не имеет смысла для машинного
		// service-токена; закрыто независимо от access_level токена.
		if ( (bool) apply_filters( 'plathix/infrastructure/service_token_active', false ) ) {
			return new \WP_Error(
				'plathix_service_token_forbidden',
				__( 'Service tokens cannot modify personal favorites.', 'plathix' ),
				[ 'status' => 403 ]
			);
		}

		// [internal]: параметр favorites объявлен required=false (клиент может слать запрос
		// без него) — has_param()-guard, а не смена схемы: отсутствие параметра не должно
		// стирать сохранённый список (симметрично PreferencesController::update_preferences()).
		// Явно переданный пустой массив (has_param=true, значение []) — легитимная очистка,
		// продолжает работать: guard не трогает этот путь.
		if ( ! $request->has_param( 'favorites' ) ) {
			return new \WP_REST_Response(['success' => true]);
		}

		$post_type = (string) $request->get_param('post_type');
		$user_id   = ($this->get_user_id)();

		$ids = array_values(
			array_filter(
				array_map('absint', (array) $request->get_param('favorites'))
			)
		);

		($this->set_favorites)($user_id, $ids, $post_type);

		return new \WP_REST_Response(['success' => true]);
	}
}
