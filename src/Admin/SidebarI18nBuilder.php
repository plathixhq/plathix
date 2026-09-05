<?php

declare(strict_types=1);

namespace Plathix\Admin;

final class SidebarI18nBuilder
{
	/**
	 * @param object|null $pt_obj
	 * @return array<string, string>
	 */
	public function build(?object $pt_obj, string $label_plural): array {
		$i18n = [
			'folders' => __( 'Folders', 'plathix' ),
			'loading' => __( 'Loading...', 'plathix' ),
			'new_folder_name' => __( 'Folder name:', 'plathix' ),
			'rename' => __( 'Rename folder:', 'plathix' ),
			'confirm_delete' => __( 'Delete this folder?', 'plathix' ),
			'folder_not_empty' => __( 'Folder is not empty.', 'plathix' ),
			'no_items_selected' => __( 'No items selected.', 'plathix' ),
			'invalid_move_target' => __( 'Open a destination folder first.', 'plathix' ),
			/* translators: %s: plural post type label e.g. "Posts", "Pages", "Products" */
			'move_selected' => sprintf( __( 'Move selected %s', 'plathix' ), mb_strtolower( $label_plural ) ),
			'add_folder' => __( '+ Folder', 'plathix' ),
			'create_subfolder' => __( 'New subfolder', 'plathix' ),
			'save_label' => __( 'Save', 'plathix' ),
			'create_label' => __( 'Create', 'plathix' ),
			'cancel_label' => __( 'Cancel', 'plathix' ),
			'ok_label' => __( 'OK', 'plathix' ),
			'folder_name_exists' => __( 'A folder with this name already exists here.', 'plathix' ),
			'rename_label' => __( 'Rename', 'plathix' ),
			'delete_label' => __( 'Move to Trash', 'plathix' ),
			'delete_confirm_title' => __( 'Move this folder to Trash? You can restore it later.', 'plathix' ),
			'delete_confirm_safe' => __( 'Files won\'t be deleted — depending on your Trash settings, they either move with the folder or become unassigned.', 'plathix' ),
			'delete_confirm_choice_hint' => __( 'Choose whether subfolders move to Trash together with this folder, or stay in place.', 'plathix' ),
			'delete_folder_only' => __( 'Move folder to Trash', 'plathix' ),
			'delete_folder_recursive' => __( 'Move folder and subfolders to Trash', 'plathix' ),
			'select_folders' => __( 'Select folders', 'plathix' ),
			'toggle_expand_all' => __( 'Expand/collapse all', 'plathix' ),
			'drag_mode' => __( 'Drag mode', 'plathix' ),
			'delete_folders' => __( 'Move folders to Trash', 'plathix' ),
			'folder_deleted_success' => __( 'Folder moved to Trash. You can restore it from there.', 'plathix' ),
			'gallery_shortcode' => __( 'Gallery shortcode', 'plathix' ),
			'documents_label' => __( 'Documents', 'plathix' ),
			'show_size_label' => __( 'Show file size', 'plathix' ),
			'retry_label' => __( 'Refresh', 'plathix' ),
			'max_depth_reached' => __( 'Maximum nesting depth reached', 'plathix' ),
			'all_files' => $pt_obj?->labels->all_items ?? __( 'All Files', 'plathix' ),
			/* translators: %s: plural post type label e.g. "posts", "pages", "products" */
			'no_folders_yet' => sprintf( __( 'No folders yet. Create your first folder to organize your %s.', 'plathix' ), mb_strtolower( $label_plural ) ),
			'no_folders_found' => __( 'No folders found.', 'plathix' ),
			'favorites' => __( 'Favorites', 'plathix' ),
			'add_favorite' => __( 'Add to favorites', 'plathix' ),
			'remove_favorite' => __( 'Remove from favorites', 'plathix' ),
			'favorites_save_failed' => __( 'Failed to save favorites.', 'plathix' ),
			'unable_refresh_nonce' => __( 'Unable to refresh nonce.', 'plathix' ),
			'request_failed' => __( 'Request failed.', 'plathix' ),
			// Shared с обычной загрузкой (upload-events.js) И с загрузкой папок (PRO FolderUpload).
			// Регистрируются платформой Free (не модулем FolderUpload): при выносе FolderUpload в PRO
			// обычная загрузка во Free должна сохранить перевод этих строк ([internal], OQ-1).
			'file_uploaded_notif' => __( 'file uploaded', 'plathix' ),
			'files_uploaded_notif' => __( 'files uploaded', 'plathix' ),
			'upload_in_progress_notice' => __( 'Upload is still running. The view will return to the upload folder when it finishes.', 'plathix' ),
			'upload_in_progress_folder_notice' => __( 'Upload is still running in the selected folder. The view will return there when it finishes.', 'plathix' ),
			'upload_reload_warning' => __( 'Uploads are still in progress. Leaving now may interrupt them.', 'plathix' ),
			'error' => __( 'Error', 'plathix' ),
			// i18n корзины (move_to_trash, restore_label, *_notif, files_selected, trash_confirm_hint)
			// перенесены в Modules\Trash\Module::add_sidebar_i18n ([internal]). trash_folder_label
			// удалён как мёртвый ключ (ноль потребителей). Без модуля JS использует fallback в t().
			'replace_success' => __( 'File replaced successfully.', 'plathix' ),
			'replace_partial_success' => __( 'File replaced, but some cleanup steps need manual review.', 'plathix' ),
			'replace_failed' => __( 'Replace failed.', 'plathix' ),
			// [internal]: индикатор "Файл заменяется…" (кнопка) и warning при сбое обновления
			// большого превью модалки (файл при этом уже заменён успешно на сервере).
			'replace_in_progress' => __( 'Replacing…', 'plathix' ),
			'replace_preview_refresh_failed' => __( 'File replaced, but the preview could not be refreshed. Reload the page to see the new file.', 'plathix' ),
			// [internal]: сервер мог реально заменить файл, но вернуть нечитаемый ответ —
			// Replace не имеет автоматического reconcile (в отличие от sidebar store),
			// поэтому сообщение прямо просит перезагрузить страницу вместо "Refreshing..."
			// из общего rest_write_indeterminate текста.
			'replace_write_indeterminate' => __( 'The file may have been replaced, but the server response could not be confirmed. Reload the page to check.', 'plathix' ),
			/* translators: 1: image width in pixels, 2: image height in pixels. */
			'replace_dimensions_format' => __( '%1$s by %2$s pixels', 'plathix' ),
			'rest_write_blocked' => __( 'The server is blocking REST write requests. Contact your hosting.', 'plathix' ),
			'rest_read_corrupted' => __( 'The server is corrupting REST responses (both /wp-json/ and rest_route returned invalid data). Contact your hosting.', 'plathix' ),
			// [internal]: 2xx-ответ на write-запрос с нечитаемым (не-JSON) телом — сервер мог
			// выполнить мутацию, но подтвердить это по ответу нельзя. Честная ошибка вместо
			// молчаливого null, чтобы store-слой инициировал reconcile, а не потерял результат.
			'rest_write_indeterminate' => __( 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.', 'plathix' ),
			'upload_failed' => __( 'Upload failed.', 'plathix' ),
			// Split-control поля "Папка" (переход + смена, popover-дерево).
			'folder_switch_move_to' => __( 'Move to folder', 'plathix' ),
			'folder_switch_load_failed' => __( 'Failed to load folders.', 'plathix' ),
			'folder_switch_move_failed' => __( 'Failed to move file.', 'plathix' ),
			'folder_switch_rate_limited' => __( 'Too many requests. Please try again later.', 'plathix' ),
			'folder_switch_moved' => __( 'Folder changed.', 'plathix' ),
			// Sort controls (search-entry.js).
			'sort_label'    => __( 'SORT', 'plathix' ),
			'sort_alpha_az' => __( 'A → Z', 'plathix' ),
			'sort_alpha_za' => __( 'Z → A', 'plathix' ),
			'sort_new'      => __( 'Newest first', 'plathix' ),
			'sort_old'      => __( 'Oldest first', 'plathix' ),
			'sort_size'     => __( 'By size', 'plathix' ),
			'sort_default'  => __( 'By default', 'plathix' ),
			'sort_folders'  => __( 'Sort', 'plathix' ),
			// Folder CRUD notifications (store/folders-crud.js, store/bulk-delete.js).
			'folder_created_notif'      => __( 'Folder created', 'plathix' ),
			'folder_renamed_notif'      => __( 'Folder renamed', 'plathix' ),
			'folder_deleted_notif'      => __( 'Moved to Trash', 'plathix' ),
			'folders_deleted_notif'     => __( 'folders moved to Trash', 'plathix' ),
			// Drag&drop reorder notice (store/folder-move.js, [internal]).
			'drag_reorder_hidden_by_sort' => __( 'Position saved. Switch sorting to "Default" to see it.', 'plathix' ),
			'folder_delete_failed_notif'  => __( 'folder could not be deleted', 'plathix' ),
			'folders_delete_failed_notif' => __( 'folders could not be deleted', 'plathix' ),
			// Bulk move notifications (dnd.js, components/BulkActions.js, store/items.js).
			'bulk_confirm_move' => __( 'items will be moved. Continue?', 'plathix' ),
			'file_moved_notif'  => __( '1 file moved', 'plathix' ),
			'files_moved_notif' => __( 'files moved', 'plathix' ),
			// Bulk-delete overlay (templates/overlays.js).
			'bulk_delete_confirm_title' => __( 'Move the selected folders to Trash? You can restore them later.', 'plathix' ),
			// [internal]: отдельный ключ (не delete_confirm_safe) — тот же текст, но
			// множественное число ("folders"), т.к. single-delete overlay использует то же
			// t()-соглашение с единственным числом через delete_confirm_safe. Общий PHP-источник
			// раньше давал грамматически неверную форму в одной из двух модалок.
			'bulk_delete_confirm_safe'  => __( 'Files won\'t be deleted — depending on your Trash settings, they either move with the folders or become unassigned.', 'plathix' ),
			'bulk_delete_has_nested'    => __( 'Some selected folders contain subfolders. Choose how to handle them:', 'plathix' ),
			'delete_folders_only'       => __( 'Move only selected, keep subfolders', 'plathix' ),
			'delete_folders_confirm'    => __( 'Move to Trash', 'plathix' ),
			'delete_folders_recursive'  => __( 'Move selected and all subfolders to Trash', 'plathix' ),
			// Aria-labels (templates/folder-tree.js).
			'create_folder' => __( 'Create folder', 'plathix' ),
			'rename_folder' => __( 'Rename folder', 'plathix' ),
			'select_folder' => __( 'Select folder', 'plathix' ),
		];

		/**
		 * Позволяет модулям добавлять свои i18n-строки в JS-конфиг сайдбара.
		 *
		 * @param array<string, string> $i18n
		 */
		return (array) apply_filters( 'plathix/sidebar/i18n', $i18n );
	}
}
