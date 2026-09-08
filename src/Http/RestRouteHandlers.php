<?php

declare(strict_types=1);

namespace Plathix\Http;

interface RestRouteHandlers
{
	public function batchCreateFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function batchDeleteFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function batchUpdateFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function bulkRestoreMedia(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function bulkTrashMedia(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function createFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function deleteFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function getFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function getFolderItems(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function getFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function moveItems(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function purgeFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function recountFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function reorderTree(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function restoreFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function trashedFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function unassignItems(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function updateFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function updatePreferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;

	/** @return list<int> */
	public function sanitizeIdsParam(mixed $value): array;
}
