<?php

declare(strict_types=1);

namespace Plathix\Http;

/**
 * Handler/sanitize-фасад REST-маршрутов папок (CTAN-102, [internal]).
 *
 * Полный контракт, который {@see RestRouteRegistry} дергает у целевого объекта, — 22
 * endpoint-обработчика + санитайзер списков id (paradigm-skeptic р.4: узкий permission-
 * интерфейс недостаточен, registry держится и за handlers, и за sanitize). Free-реализация —
 * {@see RestController}; PRO переиспользует ЕЁ ЖЕ под namespace `plathix-pro/v1`, подменяя
 * только {@see RestRoutePermissions}.
 */
interface RestRouteHandlers
{
	public function batch_create_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function batch_delete_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function batch_update_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function bulk_restore_media(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function bulk_trash_media(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function create_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function delete_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function get_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function get_folder_items(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function get_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function move_items(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function purge_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function recount_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function reorder_tree(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function restore_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function trashed_folders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function unassign_items(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function update_folder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
	public function update_preferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;

	/** @return list<int> */
	public function sanitize_ids_param(mixed $value): array;
}
