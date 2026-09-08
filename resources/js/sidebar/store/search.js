import { getRuntime, getPostType } from '../runtime.js';

function getSortByStorageKey() {
    return 'plathix_sort_by_' + getPostType();
}

// Module-level caches — safe because there is exactly one store instance.
let _ffCacheQuery = null;
let _ffCacheFoldersRef = null;
let _ffCacheVersion = null;
let _ffCacheResult = null;

let _soMCacheFoldersRef = null;
let _soMCacheVersion = null;
let _soMCacheResult = false;

let _childrenCacheSrc = null;
let _childrenCacheVersion = null;
let _childrenByParentCache = null;
let _hasChildrenSetCache = null;









function parentChainHasCycle(id, parentById) {

    const seen = new Set();
    let cur = Number(id) || 0;
    while (cur > 0) {
        if (seen.has(cur)) return true;
        seen.add(cur);
        cur = Number(parentById.get(cur)) || 0;
    }
    return false;
}





function ensureChildrenCache(ff, version) {
    if (_childrenCacheSrc === ff && _childrenCacheVersion === version) return;
    const map = new Map();
    const set = new Set();

    const parentById = new Map();
    for (const f of ff) parentById.set(Number(f.id) || 0, Number(f.parentId) || 0);

    for (const f of ff) {
        let pid = Number(f.parentId) || 0;

        if (pid > 0 && parentChainHasCycle(Number(f.id) || 0, parentById)) {
            pid = 0;
        }
        if (!map.has(pid)) map.set(pid, []);
        map.get(pid).push(f);
        if (pid > 0) set.add(pid);
        if (f?.hasChildren) set.add(Number(f.id) || 0);
    }
    _childrenCacheSrc = ff;
    _childrenCacheVersion = version;
    _childrenByParentCache = map;
    _hasChildrenSetCache = set;
}

export const searchModule = {
    searchQuery: '',
    isSearching: false,
    sortBy: 'default',

    setSortBy(value) {
        this.sortBy = value;
        try { localStorage.setItem(getSortByStorageKey(), value); } catch {}
    },

    get isSearchOnlyMode() {
        if (this.searchQuery) return false;
        if (this.shouldUseDeferredTree?.() && !this.hasLoadedFullTree) return false;
        const threshold = Number(getRuntime().searchOnlyAt ?? 500) || 500;
        const folders = this.folders;
        const version = this.foldersVersion;
        if (folders !== _soMCacheFoldersRef || version !== _soMCacheVersion) {
            _soMCacheFoldersRef = folders;
            _soMCacheVersion = version;
            let count = 0;
            for (const f of folders) {
                if (!f.isProtected) {
                    count++;
                    if (count >= threshold) break;
                }
            }
            _soMCacheResult = count >= threshold;
        }
        return _soMCacheResult;
    },

    get filteredFolders() {
        if (!this.searchQuery) {
            return this.folders;
        }

        const folders = this.folders;
        const q = this.searchQuery;
        const version = this.foldersVersion;
        if (_ffCacheQuery === q && _ffCacheFoldersRef === folders && _ffCacheVersion === version) {
            return _ffCacheResult;
        }

        const qLower = q.toLowerCase();
        const byId = new Map(folders.map((folder) => [String(folder.id), folder]));
        const included = new Set();

        folders.forEach((folder) => {
            const name = String(folder.name || '').toLowerCase();
            if (!name.includes(qLower)) {
                return;
            }

            let current = folder;
            while (current) {
                included.add(String(current.id));
                const parentId = Number(current.parentId || 0);
                if (parentId <= 0) {
                    break;
                }
                current = byId.get(String(parentId)) || null;
            }
        });

        _ffCacheQuery = q;
        _ffCacheFoldersRef = folders;
        _ffCacheVersion = version;
        _ffCacheResult = folders.filter((folder) => included.has(String(folder.id)));
        return _ffCacheResult;
    },

    // Map<parentId, folder[]> — built once per filteredFolders reference/version.
    get _childrenByParent() {
        const ff = this.filteredFolders;
        ensureChildrenCache(ff, this.foldersVersion);
        return _childrenByParentCache;
    },

    // Set<parentId> — which folder IDs have at least one child.
    get _hasChildrenSet() {
        const ff = this.filteredFolders;
        ensureChildrenCache(ff, this.foldersVersion);
        return _hasChildrenSetCache;
    },

    get systemRootFolders() {
        const trashId = Number(window.Plathix?.trashFolderId || 0);
        return this._childrenByParent.get(0)?.filter((f) => {
            if (!f.isProtected) return false;



            if (trashId > 0 && Number(f.id) === trashId
                && Number(f.count || 0) === 0 && Number(f.foldersCount || 0) === 0) return false;
            return true;
        }) ?? [];
    },

    get hasNoUserFolders() {
        return !this.folders.some((f) => !f.isProtected);
    },

    get hasNoSearchResults() {
        return !!this.searchQuery && !this.filteredFolders.some((f) => !f.isProtected);
    },






    favoriteMatchesSearch(folder) {
        if (!folder) return false;
        if (!this.searchQuery) return true;
        return String(folder.name || '').toLowerCase().includes(this.searchQuery.toLowerCase());
    },


    get _visibleFavoriteFolders() {
        const favs = this.favorites || [];
        return favs
            .map((id) => this.folders.find((f) => Number(f.id) === Number(id)) || null)
            .filter((folder) => folder !== null && this.favoriteMatchesSearch(folder));
    },


    get hasVisibleFavorites() {
        return this._visibleFavoriteFolders.length > 0;
    },


    get visibleFavoritesCount() {
        return this._visibleFavoriteFolders.length;
    },

    clearSearch() {
        document.querySelectorAll('.plathix-search__input').forEach((el) => {
            /** @type {HTMLInputElement} */ (el).value = '';
        });
        this.searchQuery = '';
    },

    async setSearchQuery(query) {
        const normalizedQuery = String(query || '');
        if (normalizedQuery && this.shouldUseDeferredTree?.() && !this.hasLoadedFullTree) {
            this.isSearching = true;
            try {
                await this.loadCompleteFolderTree({ silent: true });
            } finally {
                this.isSearching = false;
            }
        }
        this.searchQuery = normalizedQuery;






        if (normalizedQuery && typeof this.expandAncestors === 'function') {
            const qLower = normalizedQuery.toLowerCase();
            const matchIds = this.folders
                .filter((folder) => String(folder.name || '').toLowerCase().includes(qLower))
                .map((folder) => Number(folder.id));
            for (const matchId of matchIds) {
                // eslint-disable-next-line no-await-in-loop
                await this.expandAncestors(matchId);
            }
        }
    },
};
