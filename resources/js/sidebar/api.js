import { restRequest, postType, buildQuery, uploadFile, uploadMultipart } from './api/transport.js';

// submitDownload / createZip / getJobStatus УДАЛЕНЫ ([internal]): ZIP-фича уехала в
// PRO целиком — свой транспорт несёт PRO-бандл (ZipApi),
// не Free api.js. Free-сайдбар про zip-REST/AJAX не знает.

function normalizeFoldersResponse(data) {
    return Array.isArray(data?.folders) ? data.folders : [];
}

// [internal]: savePreference() вызывается fire-and-forget (без await) из нескольких мест
// (store/navigation.js, static-list/manager.js) при быстром переключении папки. Без guard'а
// сервер получает оба запроса и last-write-wins определяется порядком ПРИХОДА ответа, не
// порядком отправки — устаревший ответ может прийти позже актуального и перезаписать его.
// Абортим ещё не завершённый предыдущий запрос ТОГО ЖЕ ключа при новом вызове, чтобы на
// сервер долетал не более чем один in-flight запрос per key.
const _savePreferenceControllers = new Map();

// [internal] ([internal]): saveFavorites() вызывается fire-and-forget из toggleFavorite и
// шлёт ПОЛНЫЙ массив (last-write-wins) — тот же класс гонки, что у savePreference выше:
// порядок ПРИХОДА ответов, а не отправки, решает, что останется на сервере. Endpoint один
// и ключа нет, поэтому вместо Map — одиночный контроллер: абортим ещё не завершённый
// предыдущий запрос при новом вызове, чтобы in-flight был не более чем один.
let _saveFavoritesController = null;

export const Api = {
    restGet(path, retry = true, signal = undefined) {
        return restRequest(path, { method: 'GET', retry, signal });
    },

    getFolders(params = {}, signal = undefined) {
        return restRequest(`folders?${buildQuery(params)}`, { method: 'GET', signal });
    },

    async getFolderCounts(folderIds) {
        const data = await this.getFolders({ ids: folderIds, fields: 'id,count' });
        return normalizeFoldersResponse(data);
    },

    getFolderItems(folderId, params = {}, signal = undefined) {
        return restRequest(`folders/${folderId}/items?${buildQuery(params)}`, { method: 'GET', signal });
    },

    // Один терм-папка по id с полным DTO (count/color/hasChildren/parentId из get_all_cached).
    // Используется после создания папки, чтобы вставить ПРАВДИВЫЙ объект вместо собранной вручную
    // болванки ([internal]): сервер при дубль-имени возвращает id существующей папки, и только
    // GET даёт её настоящие данные. Возвращает { folder, taxonomy }; 404 если терм не найден.
    getFolder(id, signal = undefined) {
        return restRequest(`folders/${Number(id)}?${buildQuery()}`, { method: 'GET', signal });
    },

    createFolder(name, parentId = 0) {
        return restRequest('folders', {
            method: 'POST',
            data: { name, parent_id: parentId, post_type: postType() },
        });
    },

    renameFolder(id, name) {
        return restRequest(`folders/${id}`, {
            method: 'POST',
            data: { name, post_type: postType() },
        });
    },

    deleteFolder(id, onChildren = 'delete') {
        return restRequest(`folders/${id}?${buildQuery()}&on_children=${onChildren}`, { method: 'DELETE' });
    },

    // Корзина папок ([internal]/104): список помеченных папок и восстановление на место.
    getTrashedFolders(signal = undefined) {
        return restRequest(`folders/trashed?${buildQuery()}`, { method: 'GET', signal });
    },

    restoreFolder(id) {
        return restRequest(`folders/${id}/restore`, {
            method: 'POST',
            data: { post_type: postType() },
        });
    },

    purgeFolder(id) {
        return restRequest(`folders/${id}/purge?${buildQuery()}`, { method: 'DELETE' });
    },

    setFolderColor(id, color) {
        return restRequest(`folders/${id}`, {
            method: 'POST',
            data: { color, post_type: postType() },
        });
    },

    moveFolderParent(id, parentId) {
        return restRequest(`folders/${id}`, {
            method: 'POST',
            data: { parent_id: parentId, post_type: postType() },
        });
    },

    moveFolderToSiblingOf(id, targetParentId, position) {
        return restRequest(`folders/${id}`, {
            method: 'POST',
            data: { parent_id: targetParentId, position, post_type: postType() },
        });
    },

    moveItemsBulk(itemIds, folderId) {
        return restRequest(`folders/${folderId}/items`, {
            method: 'POST',
            data: { item_ids: itemIds, post_type: postType() },
        });
    },

    unassignItems(itemIds) {
        return restRequest('items', {
            method: 'DELETE',
            data: { item_ids: itemIds, post_type: postType() },
        });
    },

    savePreference(key, value) {
        _savePreferenceControllers.get(key)?.abort();
        const controller = new AbortController();
        _savePreferenceControllers.set(key, controller);

        return restRequest('preferences', {
            method: 'POST',
            data: { [key]: value, post_type: postType() },
            signal: controller.signal,
        }).finally(() => {
            if (_savePreferenceControllers.get(key) === controller) {
                _savePreferenceControllers.delete(key);
            }
        });
    },

    saveFavorites(ids) {
        _saveFavoritesController?.abort();
        const controller = new AbortController();
        _saveFavoritesController = controller;

        return restRequest('favorites', {
            method: 'POST',
            data: { favorites: ids, post_type: postType() },
            signal: controller.signal,
        }).finally(() => {
            if (_saveFavoritesController === controller) {
                _saveFavoritesController = null;
            }
        });
    },

    async getFolderCount(folderId) {
        const folders = await this.getFolderCounts([folderId]);
        const folder = folders.find((item) => Number(item.id) === Number(folderId));
        return Number(folder?.count || 0);
    },

    reorderTree(items) {
        return restRequest('folders/reorder-tree', {
            method: 'POST',
            data: { items, post_type: postType() },
        });
    },

    uploadFile,

    replaceAttachment(id, file, signal = undefined) {
        return uploadMultipart(`attachments/${id}/replace`, file, { signal });
    },

    getFolderSize(folderId) {
        return restRequest(`folders/${folderId}/size?${buildQuery()}`, { method: 'GET' });
    },

    trashMedia(ids) {
        return restRequest('media/bulk-trash', {
            method: 'POST',
            data: { ids, post_type: postType() },
        });
    },

    restoreMedia(ids, targetFolderId = 0) {
        return restRequest('media/bulk-restore', {
            method: 'POST',
            data: { ids, target_folder_id: targetFolderId, post_type: postType() },
        });
    },

    // getImportAdapters / startImport / exportStructure УДАЛЕНЫ ([internal], [internal]):
    // REST-маршруты /imports и /export сняты вместе с каналом — он авторизовался слабее
    // реального AJAX-пути той же операции. Импорт из UI идёт через admin-ajax
    // (resources/js/import/index.js), экспорт — через admin-post-форму на странице Tools;
    // программный контракт остаётся у PHP-фасада PlathixAPI::importExport().
    //
    // getAuditEntries / recordAudit / exportAudit УДАЛЕНЫ ([internal], [internal]):
    // журнал аудита — функциональность PRO; Free маршруты /audit и /audit/export никогда
    // не регистрировал, обёртки уходили в rest_no_route. Эмиссия plathix/audit/record во
    // Free остаётся (Free — её главный источник и сам же подписчик), удалены только
    // JS-читатели чужого журнала.
};
