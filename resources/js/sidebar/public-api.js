import Alpine from 'alpinejs';
import { Api, DEFAULT_ON_CHILDREN } from './api.js';
import { createFolderSelector } from './folder-selector.js';

function getStore() {
    try {
        return Alpine.store('plathix');
    } catch {
        return null;
    }
}

export function createPublicApi() {
    return {
        getStore,

        getState() {
            const store = getStore();
            if (!store) {
                return null;
            }

            return {
                openId: Number(store.openId || 0),
                selected: Array.isArray(store.selected) ? [...store.selected] : [],
                folders: Array.isArray(store.folders) ? [...store.folders] : [],
                isLoading: !!store.isLoading,
                isUploading: !!store.isUploading,
            };
        },

        onReady(callback) {
            if (typeof callback !== 'function') {
                return;
            }

            if (window.__PlathixApiReady) {
                callback(window.PlathixApi);
                return;
            }

            window.addEventListener(
                'plathix:ready',
                () => callback(window.PlathixApi),
                { once: true }
            );
        },

        

        refreshFolders({ silent = true } = {}) {
            const store = getStore();
            return store?.refreshFolders ? store.refreshFolders({ silent }) : Promise.resolve(null);
        },

        openFolder(folderId) {
            const store = getStore();
            return store?.openFolder ? store.openFolder(folderId) : Promise.resolve(null);
        },

        getFolders(params = {}, signal = undefined) {
            return Api.getFolders(params, signal);
        },

        getFolderItems(folderId, params = {}, signal = undefined) {
            return Api.getFolderItems(folderId, params, signal);
        },

        createFolder(name, parentId = 0) {
            return Api.createFolder(name, parentId);
        },

        renameFolder(id, name) {
            return Api.renameFolder(id, name);
        },

        deleteFolder(id, onChildren = DEFAULT_ON_CHILDREN) {
            return Api.deleteFolder(id, onChildren);
        },

        setFolderColor(id, color) {
            return Api.setFolderColor(id, color);
        },

        moveFolderParent(id, parentId) {
            return Api.moveFolderParent(id, parentId);
        },

        moveItemsBulk(itemIds, folderId) {
            return Api.moveItemsBulk(itemIds, folderId);
        },

        reorderTree(items) {
            return Api.reorderTree(items);
        },

        savePreference(key, value) {
            return Api.savePreference(key, value);
        },













        createFolderSelector(target, options = {}) {
            return createFolderSelector(target, options);
        },
    };
}

export function installPublicApi() {
    if (window.PlathixApi) {
        return window.PlathixApi;
    }

    window.PlathixApi = createPublicApi();
    return window.PlathixApi;
}
