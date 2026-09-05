import Alpine from 'alpinejs';
import { Api } from './api.js';
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

        /**
         * Перечитать дерево папок целиком — для внешнего кода, изменившего папки в обход
         * сайдбара.
         *
         * [internal]: наружу отдаётся ОДНА опция, а не весь объект стора. Прозрачный
         * проброс выпускал все шесть параметров `store.refreshFolders`, из которых
         * `params` — прямой канал порчи состояния: при `fields` сервер отдаёт объекты без
         * `parentId`/`hasChildren` (FolderReadController.php), и такой ответ, попав в
         * `this.folders`, схлопывает дерево в плоский список сирот. Остальные
         * (`replace`/`markParentLoaded`/`markFullTree`) — внутренняя механика частичной
         * догрузки, снаружи бессмысленная.
         *
         * Фильтрованный список папок берётся не здесь, а через `getFolders(params)` ниже:
         * тот отдаёт результат вызывающему и стор не трогает.
         *
         * `silent` по умолчанию `true` (в сторе — `false`): внешний вызов обновляет дерево
         * после чужой операции, и мигание спиннером в чужом UI — неожиданный побочный
         * эффект. Кому нужен индикатор, передаёт `{ silent: false }` явно.
         *
         * @param {{ silent?: boolean }} [options]
         */
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

        deleteFolder(id, onChildren = 'delete') {
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

        // createZip / getJobStatus УДАЛЕНЫ ([internal]): ZIP-фича уехала в PRO,
        // публичный Free-фасад про zip больше не знает.

        // getImportAdapters / startImport / exportStructure УДАЛЕНЫ ([internal], [internal]):
        // legacy REST-канал /imports и /export снят целиком — он авторизовался слабее
        // реального пути той же операции. Программный контракт импорта/экспорта живёт в
        // PHP-фасаде PlathixAPI::importExport().

        // getAuditEntries / recordAudit / exportAudit УДАЛЕНЫ ([internal], [internal]):
        // журнал аудита — функциональность PRO, и собственный доступ к нему несёт PRO;
        // публичный Free-фасад про audit больше не знает.

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
