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

// [internal]: cycle-guard. Раньше строковый генератор дерева жёстко резал глубину на
// TEMPLATE_MAX_DEPTH=30, что попутно защищало от циклических данных (папка — сама себе предок
// через битый parentId). После перехода на рантайм-рекурсию (x-html) такого потолка нет:
// цикл A→B→A дал бы бесконечное монтирование Alpine → вис браузера. Обрубаем ребро, замыкающее
// цикл, на этапе построения карты (слой данных, не шаблон): если родитель папки через цепочку
// parentId оказывается её же потомком, папка становится сиротой на корне (pid=0), а не
// участником цикла. Ацикличные данные не затрагиваются — подъём по предкам достигает корня без
// повтора, ребро связывается как прежде.
function parentChainHasCycle(id, parentById) {
    // true, если подъём от id по parentId зацикливается (id встречается повторно).
    const seen = new Set();
    let cur = Number(id) || 0;
    while (cur > 0) {
        if (seen.has(cur)) return true;
        seen.add(cur);
        cur = Number(parentById.get(cur)) || 0;
    }
    return false;
}

// [internal]: version — this.foldersVersion (tree-state.js), передаётся явно, так как
// это свободная функция без доступа к this. Ловит in-place patchFolder-мутацию, которая меняет
// содержимое ff (через this.folders), но не саму ссылку ff, если ff === this.folders (без
// активного поиска).
function ensureChildrenCache(ff, version) {
    if (_childrenCacheSrc === ff && _childrenCacheVersion === version) return;
    const map = new Map();
    const set = new Set();
    // id -> parentId, для проверки цепочки предков на цикл.
    const parentById = new Map();
    for (const f of ff) parentById.set(Number(f.id) || 0, Number(f.parentId) || 0);

    for (const f of ff) {
        let pid = Number(f.parentId) || 0;
        // Ребро замыкает цикл — не связываем как ребёнка, поднимаем на корень.
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
            // Trash-узел прячем только когда корзина пуста ПОЛНОСТЬЮ: и по файлам (count), и по
            // папкам (foldersCount). count = только файлы ([internal]), поэтому корзина с
            // папками, но без файлов, имела count===0 и ошибочно скрывалась ([internal]).
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

    // [internal] ([internal]): избранное — потребитель ЕДИНОГО searchQuery, не заводит
    // свой поиск. Предикат совпадения живёт у владельца состояния поиска (search-модуль),
    // а не дублируется строкой сравнения в favorites-template.
    // Совпадение: нет активного запроса ИЛИ имя папки содержит запрос (регистронезависимо,
    // как filteredFolders). null-folder → false.
    favoriteMatchesSearch(folder) {
        if (!folder) return false;
        if (!this.searchQuery) return true;
        return String(folder.name || '').toLowerCase().includes(this.searchQuery.toLowerCase());
    },

    // Существующие избранные папки, видимые при текущем поиске (для скрытия строк и блока).
    get _visibleFavoriteFolders() {
        const favs = this.favorites || [];
        return favs
            .map((id) => this.folders.find((f) => Number(f.id) === Number(id)) || null)
            .filter((folder) => folder !== null && this.favoriteMatchesSearch(folder));
    },

    // true, если блок «Избранное» должен показываться (есть хотя бы одна видимая при поиске).
    get hasVisibleFavorites() {
        return this._visibleFavoriteFolders.length > 0;
    },

    // Счётчик избранного: при активном поиске — число совпадений, иначе — всех.
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

        // [internal] ([internal]): раскрыть путь до каждой найденной папки в основном
        // дереве. filteredFolders уже включает предков совпадений, но collapsedIds не
        // трогает — под свёрнутым родителем узел совпадения не отрисуется. Раскрываем
        // предков совпадений через expandAncestors (в deferred догрузит детей по цепочке).
        // Полное дерево к этому моменту загружено (см. ветку выше), совпадения известны.
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
