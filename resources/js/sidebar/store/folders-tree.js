import { getPostType } from '../runtime.js';
import { collectAncestorIds } from './folder-tree-utils.js';

function getCollapsedStorageKey() {
    return 'plathix_collapsed_' + getPostType();
}

export const foldersTreeModule = {
    folderSelectMode: false,
    folderDragMode: false,
    selectedFolderIds: [],
    _selectedFolderIdsSet: new Set(),
    isFolderDragging: false,
    folderDragId: null,
    folderDropId: null,
    folderDropPos: null,
    collapsedIds: (() => {
        try {
            return JSON.parse(localStorage.getItem(getCollapsedStorageKey()) || '{}');
        } catch { return {}; }
    })(),

    isCollapsed(folderId) {
        if (this.shouldUseDeferredTree() && !this.hasLoadedChildren(folderId)) {
            return true;
        }
        return !!this.collapsedIds[folderId];
    },

    getCollapsibleFolderIds() {
        return this.folders
            .map((folder) => Number(folder.id))
            .filter((folderId) => this._hasChildrenSet.has(folderId));
    },

    toggleCollapse(folderId) {
        if (this.collapsedIds[folderId]) {
            delete this.collapsedIds[folderId];
        } else {
            this.collapsedIds[folderId] = true;
        }
        try {
            localStorage.setItem(getCollapsedStorageKey(), JSON.stringify(this.collapsedIds));
        } catch {}
    },

    /**
     * Раскрыть все ветки-предки папки, чтобы её узел отрисовался в основном дереве
     * ([internal], [internal]). Используется при переходе в папку из «Избранного»
     * и при создании подпапки: без раскрытия предков узел не в DOM и поле ввода не видно.
     *
     * Две части:
     * 1. Раскрытие collapsedIds — удаляем предков (форма `delete`, как toggleCollapse,
     *    чтобы не оставлять осиротевших `{id:false}` в persist/collapseAll-периметре).
     * 2. Deferred-tree: видимость узла управляется не только collapsedIds. isCollapsed()
     *    возвращает true при shouldUseDeferredTree() && !hasLoadedChildren(id) независимо
     *    от collapsedIds. Поэтому в deferred-режиме догружаем детей предков по цепочке
     *    (от корня к папке) через существующий loadFolderChildren — иначе ветка остаётся
     *    свёрнутой и баг #154 воспроизводится на деревьях > 200 папок.
     *
     * @param {number|string} folderId
     * @returns {Promise<void>}
     */
    async expandAncestors(folderId) {
        const ancestors = collectAncestorIds(this.folders, folderId);
        if (ancestors.length === 0) {
            return;
        }

        for (const ancestorId of ancestors) {
            delete this.collapsedIds[ancestorId];
        }
        try {
            localStorage.setItem(getCollapsedStorageKey(), JSON.stringify(this.collapsedIds));
        } catch {}

        // deferred-tree: догрузить детей предков по порядку от корня к папке
        if (this.shouldUseDeferredTree()) {
            for (const ancestorId of ancestors) {
                if (!this.hasLoadedChildren(ancestorId)) {
                    // silent: без спиннера — раскрытие пути должно быть незаметным
                    // eslint-disable-next-line no-await-in-loop
                    await this.loadFolderChildren(ancestorId, { silent: true });
                }
            }
        }
    },

    expandAll() {
        this.collapsedIds = {};
        try {
            localStorage.setItem(getCollapsedStorageKey(), '{}');
        } catch {}
    },

    collapseAll() {
        const ids = {};
        this.getCollapsibleFolderIds().forEach((folderId) => { ids[folderId] = true; });
        this.collapsedIds = ids;
        try {
            localStorage.setItem(getCollapsedStorageKey(), JSON.stringify(ids));
        } catch {}
    },

    toggleExpandAll() {
        const collapsibleFolderIds = this.getCollapsibleFolderIds();
        const anyExpanded = collapsibleFolderIds.some((folderId) => !this.collapsedIds[folderId]);
        if (anyExpanded) { this.collapseAll(); } else { this.expandAll(); }
    },

    async toggleExpandAllLoaded() {
        if (this.shouldUseDeferredTree() && !this.hasLoadedFullTree) {
            await this.loadCompleteFolderTree({ silent: false });
        }
        this.toggleExpandAll();
    },

    toggleFolderSelectMode() {
        if (this.newFolderParentId !== null && !this.newFolderName.trim()) {
            this.hideNewFolderForm();
        }
        if (!this.folderSelectMode) {
            this.folderDragMode = false;
        }
        this.folderSelectMode = !this.folderSelectMode;
        this.selectedFolderIds = [];
        this._selectedFolderIdsSet = new Set();
    },

    toggleFolderSelected(folderId) {
        const id = Number(folderId);
        const idx = this.selectedFolderIds.indexOf(id);
        if (idx === -1) {
            this.selectedFolderIds.push(id);
            this._selectedFolderIdsSet.add(id);
        } else {
            this.selectedFolderIds.splice(idx, 1);
            this._selectedFolderIdsSet.delete(id);
        }
    },

    isFolderSelected(folderId) {
        return this._selectedFolderIdsSet.has(Number(folderId));
    },

    toggleFolderDragMode() {
        if (!this.folderDragMode) {
            this.folderSelectMode = false;
            this.selectedFolderIds = [];
            this._selectedFolderIdsSet = new Set();
        }
        this.folderDragMode = !this.folderDragMode;
    },
};
