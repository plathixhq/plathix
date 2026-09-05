<?php

declare(strict_types=1);

namespace Plathix\Http;

/**
 * Permission-роль маршрутного фасада (CTAN-102): три уровня доступа маршрутов папок.
 * Free — {@see RestController} (attachment-гейт + {@see Authorization}); PRO-маршруты
 * подставляют свою реализацию (свой type-check по PRO-опции + Authorization::authorize).
 */
interface RestRoutePermissions
{
	public function can_view(\WP_REST_Request $request): bool;
	public function can_edit(\WP_REST_Request $request): bool;
	public function can_manage(\WP_REST_Request $request): bool;
}
