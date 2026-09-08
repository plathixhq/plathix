import { mergeStore } from './store/utils.js';
import { getPostType } from './runtime.js';

const VALID_SORT_BY = ['default', 'alpha', 'alpha_z', 'new', 'old', 'size'];

function getSortByStorageKey() {
    return 'plathix_sort_by_' + getPostType();
}

function readStoredSortBy() {
    try {
        const stored = localStorage.getItem(getSortByStorageKey());
        return VALID_SORT_BY.includes(stored) ? stored : 'default';
    } catch { return 'default'; }
}

const searchStubs = {
    _searchImpl: null,
    searchQuery: '',
    isSearching: false,
    sortBy: readStoredSortBy(),
    get isSearchOnlyMode()   { return this._searchImpl ? this._searchImpl.isSearchOnlyMode.call(this)   : false; },
    get hasNoSearchResults() { return this._searchImpl ? this._searchImpl.hasNoSearchResults.call(this) : false; },
    get hasNoUserFolders()   { return this._searchImpl ? this._searchImpl.hasNoUserFolders.call(this)   : false; },
    get filteredFolders()    { return this._searchImpl ? this._searchImpl.filteredFolders.call(this)    : this.folders; },
    get _childrenByParent()  { return this._searchImpl ? this._searchImpl._childrenByParent.call(this)  : new Map(); },
    get _hasChildrenSet()    { return this._searchImpl ? this._searchImpl._hasChildrenSet.call(this)    : new Set(); },
    get systemRootFolders()  { return this._searchImpl ? this._searchImpl.systemRootFolders.call(this)  : []; },



    favoriteMatchesSearch(folder)   { return this._searchImpl ? this._searchImpl.favoriteMatchesSearch.call(this, folder) : (folder !== null && folder !== undefined); },
    get _visibleFavoriteFolders()   { return this._searchImpl ? this._searchImpl._visibleFavoriteFolders.call(this) : (this.favorites || []).map((id) => this.folders.find((f) => Number(f.id) === Number(id)) || null).filter((f) => f !== null); },
    get hasVisibleFavorites()       { return this._searchImpl ? this._searchImpl.hasVisibleFavorites.call(this) : this._visibleFavoriteFolders.length > 0; },
    get visibleFavoritesCount()     { return this._searchImpl ? this._searchImpl.visibleFavoritesCount.call(this) : this._visibleFavoriteFolders.length; },
    setSearchQuery() {},
    setSortBy(value) {
        this.sortBy = value;
        try { localStorage.setItem(getSortByStorageKey(), value); } catch {}
    },
    clearSearch() {},
};
import { treeStateModule } from './store/tree-state.js';
import { selectionStateModule } from './store/selection-state.js';
import { uiStateModule } from './store/ui-state.js';
import { integrationStateModule } from './store/integration-state.js';
import { notificationsModule } from './store/notifications.js';


const favoritesStubs = {
    favorites: [],
    _favoritesSet: new Set(),
    isFavorite() { return false; },
    toggleFavorite() {},
};


const folderInfoStubs = {
    showFolderInfo: false,
    toggleShowFolderInfo() {},
    folderInfoLine() { return ''; },
};





const folderUploadStubs = {
    folderUpload: { active: false, phase: 'idle', folderName: '', total: 0, done: 0, currentFile: '', error: null },
    triggerFolderUpload() {},
    startFolderUpload() {},
    abortFolderUpload() {},
};





import { foldersCrudModule } from './store/folders-crud.js';
import { foldersTreeModule } from './store/folders-tree.js';
import { bulkDeleteModule } from './store/bulk-delete.js';
import { navigationModule } from './store/navigation.js';
import { itemsModule } from './store/items.js';
import { folderMoveModule } from './store/folder-move.js';
import { mediaDeleteModule } from './store/media-delete.js';
import { selectionModule } from './store/selection.js';
import { colorEditModule } from './store/color-edit.js';

export const sidebarStore = mergeStore(
    treeStateModule,
    selectionStateModule,
    uiStateModule,
    integrationStateModule,
    notificationsModule,
    favoritesStubs,
    folderInfoStubs,
    folderUploadStubs,
    searchStubs,
    foldersCrudModule,
    foldersTreeModule,
    bulkDeleteModule,
    navigationModule,
    itemsModule,
    folderMoveModule,
    mediaDeleteModule,
    selectionModule,
    colorEditModule,
);
