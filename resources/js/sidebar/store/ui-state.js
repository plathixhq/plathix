import { getRuntime } from '../runtime.js';

const runtime = getRuntime();

export const uiStateModule = {
    isLoading: !!runtime.deferFoldersBootstrap && !(Array.isArray(runtime.folders) && runtime.folders.length > 0),
    alertMessage: null,
    error: null,


    selectedMediaCount: 0,
    contextMenuFolderId: 0,





    folderColorStyle(folder) {
        return this._colorImpl ? this._colorImpl.folderColorStyle(folder) : '';
    },

    folderColorFill(folder) {
        return this._colorImpl ? this._colorImpl.folderColorFill(folder) : 'none';
    },

    _colorImpl: null,






    //







    isCurrentFolderTrashed() {
        void this._trashedFolderIdsVersion;
        return this._trashImpl ? this._trashImpl.isCurrentFolderTrashed() : false;
    },

    _trashImpl: null,
    _trashedFolderIdsVersion: 0,

    async withLoading(fn, { rethrow = false } = {}) {
        this.isLoading = true;
        try {
            return await fn();
        } catch (error) {
            this.error = error.message;
            if (rethrow) {
                throw error;
            }
        } finally {
            this.isLoading = false;
        }
    },

    resetTransientState() {
        this.selected = [];
        this.isLoading = false;
        this.isSearching = false;
        this.searchQuery = '';
        this.error = null;
        const input = document.querySelector('.plathix-search__input');
        if (input) {
            input.value = '';
        }
    },

    cleanup() {
        if (this._searchAbort) {
            this._searchAbort.abort();
            this._searchAbort = null;
        }
        window.clearTimeout(this._searchTimer);
        window.clearTimeout(this._prefTimer);
        this._searchTimer = null;
        this._prefTimer = null;
        this.resetTransientState();
    },
};
