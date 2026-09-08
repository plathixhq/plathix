import { restRequest, postType, buildQuery, uploadFile, uploadMultipart } from './api/transport.js';





function normalizeFoldersResponse(data) {
    return Array.isArray(data?.folders) ? data.folders : [];
}







const _savePreferenceControllers = new Map();






let _saveFavoritesController = null;





export const DEFAULT_ON_CHILDREN = 'delete';

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

    deleteFolder(id, onChildren = DEFAULT_ON_CHILDREN) {
        return restRequest(`folders/${id}?${buildQuery()}&on_children=${onChildren}`, { method: 'DELETE' });
    },


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



        return uploadMultipart(`attachments/${id}/replace`, file, { signal, includePostType: false });
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






    //





};
