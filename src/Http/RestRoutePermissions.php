<?php

declare(strict_types=1);

namespace Plathix\Http;

interface RestRoutePermissions
{
	public function canView(\WP_REST_Request $request): bool;
	public function canEdit(\WP_REST_Request $request): bool;
	public function canManage(\WP_REST_Request $request): bool;
}
