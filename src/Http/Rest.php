<?php

declare(strict_types=1);

namespace Plathix\Http;

/**
 * Тонкий платформенный REST-фундамент.
 *
 * Единый источник namespace и permission-контракта для REST-маршрутов Plathix.
 * Введён, чтобы фичевые модули регистрировали свои маршруты, завися от этого
 * тонкого слоя, а не от legacy-класса `RestController`. Намеренно минимален:
 * namespace + permission, без «новой REST-системы».
 */
final class Rest
{
	use RestControllerHelpers;

	/** REST-namespace всех маршрутов Plathix (источник истины). */
	public const NAMESPACE = 'plathix/' . RestController::API_VERSION;

	/**
	 * Permission-callback для операций редактирования (replace, move, и т.п.).
	 *
	 * Standalone-обёртка над платформенным `RestController::check('assign', …)` —
	 * не требует инстанса контроллера, поэтому пригодна для регистрации маршрутов
	 * прямо из модуля. Поведение идентично `RestController::can_edit`.
	 */
	public static function can_edit(\WP_REST_Request $request): bool {
		return RestController::check( 'assign', self::request_scalar( $request->get_param( 'post_type' ) ) );
	}
}
