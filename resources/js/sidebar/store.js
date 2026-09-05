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
    // [internal]/102: избранное фильтруется единым searchQuery. Стабы нужны, т.к.
    // favorites-template (favorites-бандл) вызывает эти геттеры до/без загрузки search-entry.
    // Без search-impl: нет фильтра поиска → все избранные видимы (поведение как без поиска).
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
// Stub-поля favorites — favorites-entry.js домёрживает реальную логику через Object.assign на plathix:ready.
// Без favorites-entry: favorites=[] → x-if="favorites.length>0" = false, блок не рендерится (graceful degradation).
const favoritesStubs = {
    favorites: [],
    _favoritesSet: new Set(),
    isFavorite() { return false; },
    toggleFavorite() {},
};
// Stub-поля folder-info — folder-info-entry.js патчит их реальной логикой через Object.assign.
// Необходимы чтобы Alpine не падал когда folder-info бандл не загружен (PRO-фича).
const folderInfoStubs = {
    showFolderInfo: false,
    toggleShowFolderInfo() {},
    folderInfoLine() { return ''; },
};
// Stub-поля folder-upload — фича уехала в PRO целиком ([internal]). PRO-бандл
// folder-upload патчит эти стабы реальной логикой через Object.assign при plathix:ready
// (plathix-pro standalone.js). Стабы остаются во Free чтобы Alpine не падал БЕЗ PRO: кнопка
// toolbar под гейтом features?.folderUpload зовёт triggerFolderUpload() (no-op без PRO). Без
// PRO overlay не рендерится (уехал), поэтому folderUpload.* читается только стабом-заглушкой.
const folderUploadStubs = {
    folderUpload: { active: false, phase: 'idle', folderName: '', total: 0, done: 0, currentFile: '', error: null },
    triggerFolderUpload() {},
    startFolderUpload() {},
    abortFolderUpload() {},
};
// Stub-поля shortcode-builder УДАЛЕНЫ ([internal]): билдер уехал в PRO целиком, включая
// триггеры. Free-сайдбар больше не рендерит кнопки билдера (заменены нейтральными слотами
// toolbarExtra / plathix-folder-row-actions / plathix-context-menu-items), поэтому store-стабы
// (`shortcodeBuilderLaunch`, `openShortcodeBuilder`) во Free не нужны — их несёт PRO-бандл
// (domerge через Object.assign на plathix:ready). Без PRO кнопок нет → Alpine к полям не обращается.
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
