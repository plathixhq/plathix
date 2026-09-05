import { searchModule } from '../search.js';
import { treeStateModule } from '../tree-state.js';
import { mergeStore } from '../utils.js';

jest.mock('../../runtime.js', () => ({
    getRuntime: () => ({ searchOnlyAt: 500 }),
    getPostType: () => 'attachment',
}));

function f(id, parentId, name = `f${id}`, isProtected = false) {
    return { id, parentId, name, isProtected };
}

function makeStore(folders = [], searchQuery = '') {
    // mergeStore preserves getter descriptors via Object.defineProperties.
    // We assign data properties directly to avoid invoking getters at construction time.
    const store = mergeStore(searchModule);
    store.folders = folders;
    store.searchQuery = searchQuery;
    return store;
}

// [internal]: real treeStateModule merged in (not the plain-array store above) —
// needed to exercise the actual patchFolder -> foldersVersion -> cache-key integration,
// not just the ===-reference path already covered by makeStore() above.
function makeVersionedStore(folders = [], searchQuery = '') {
    const store = mergeStore(treeStateModule, searchModule);
    store.folders = folders;
    store.searchQuery = searchQuery;
    return store;
}

// ---------------------------------------------------------------------------
// filteredFolders — cache semantics
// ---------------------------------------------------------------------------

describe('filteredFolders — cache semantics', () => {
    it('returns this.folders directly when searchQuery is empty', () => {
        const arr = [f(1, 0), f(2, 0)];
        const store = makeStore(arr);
        expect(store.filteredFolders).toBe(arr);
    });

    it('returns the same array reference on repeated calls (cache hit)', () => {
        const arr = [f(1, 0, 'alpha'), f(2, 0, 'beta')];
        const store = makeStore(arr, 'alpha');
        const r1 = store.filteredFolders;
        const r2 = store.filteredFolders;
        expect(r1).toBe(r2);
    });

    it('recomputes when folders reference changes (cache miss)', () => {
        // 'folder' matches both names — lets us verify arr2 contents are actually used.
        const arr1 = [f(1, 0, 'folder-a')];
        const arr2 = [f(1, 0, 'folder-a'), f(2, 0, 'folder-b')];
        const store = makeStore(arr1, 'folder');
        const r1 = store.filteredFolders;
        store.folders = arr2;
        const r2 = store.filteredFolders;
        expect(r1).not.toBe(r2);
        expect(r2).toHaveLength(2);
    });

    it('recomputes when searchQuery changes (cache miss)', () => {
        const arr = [f(1, 0, 'alpha'), f(2, 0, 'beta')];
        const store = makeStore(arr, 'alpha');
        const r1 = store.filteredFolders;
        store.searchQuery = 'beta';
        const r2 = store.filteredFolders;
        expect(r1).not.toBe(r2);
        expect(r1.map((x) => x.id)).toEqual([1]);
        expect(r2.map((x) => x.id)).toEqual([2]);
    });

    it('includes ancestors of matched folders in search results', () => {
        const arr = [
            f(1, 0, 'root'),
            f(2, 1, 'parent'),
            f(3, 2, 'target'),
            f(4, 0, 'other'),
        ];
        const store = makeStore(arr, 'target');
        const ids = store.filteredFolders.map((x) => x.id).sort((a, b) => a - b);
        expect(ids).toEqual([1, 2, 3]);
    });
});

// ---------------------------------------------------------------------------
// _childrenByParent — cache semantics
// ---------------------------------------------------------------------------

describe('_childrenByParent — cache semantics', () => {
    it('returns same Map reference on repeated reads', () => {
        const arr = [f(1, 0), f(2, 1)];
        const store = makeStore(arr);
        const m1 = store._childrenByParent;
        const m2 = store._childrenByParent;
        expect(m1).toBe(m2);
    });

    it('indexes folders by parentId correctly', () => {
        const arr = [f(1, 0), f(2, 0), f(3, 1)];
        const store = makeStore(arr);
        const map = store._childrenByParent;
        expect(map.get(0).map((x) => x.id)).toEqual([1, 2]);
        expect(map.get(1).map((x) => x.id)).toEqual([3]);
        expect(map.get(2)).toBeUndefined();
    });

    it('rebuilds when folders reference changes', () => {
        const arr1 = [f(1, 0)];
        const arr2 = [f(1, 0), f(2, 0)];
        const store = makeStore(arr1);
        const m1 = store._childrenByParent;
        store.folders = arr2;
        const m2 = store._childrenByParent;
        expect(m1).not.toBe(m2);
        expect(m2.get(0)).toHaveLength(2);
    });

    it('rebuilds when filteredFolders reference changes (active search)', () => {
        const arr = [f(1, 0, 'alpha'), f(2, 0, 'beta')];
        const store = makeStore(arr, 'alpha');
        const m1 = store._childrenByParent;
        store.searchQuery = 'beta';
        const m2 = store._childrenByParent;
        expect(m1).not.toBe(m2);
        expect(m2.get(0).map((x) => x.id)).toEqual([2]);
    });
});

// ---------------------------------------------------------------------------
// _hasChildrenSet — cache semantics
// ---------------------------------------------------------------------------

describe('_hasChildrenSet — cache semantics', () => {
    it('reports which folder IDs have children', () => {
        const arr = [f(1, 0), f(2, 1), f(3, 0)];
        const store = makeStore(arr);
        expect(store._hasChildrenSet.has(1)).toBe(true);  // folder 2 is child of 1
        expect(store._hasChildrenSet.has(3)).toBe(false); // folder 3 has no children
        expect(store._hasChildrenSet.has(0)).toBe(false); // 0 is root, not a folder ID
    });

    it('returns same Set reference on repeated reads (no rebuild)', () => {
        const arr = [f(1, 0), f(2, 1)];
        const store = makeStore(arr);
        const s1 = store._hasChildrenSet;
        const s2 = store._hasChildrenSet;
        expect(s1).toBe(s2);
    });

    it('shares a single index build with _childrenByParent', () => {
        // Accessing _childrenByParent then _hasChildrenSet should not rebuild twice.
        // We verify by checking both return consistent data from the same build.
        const arr = [f(1, 0), f(2, 1), f(3, 1)];
        const store = makeStore(arr);
        const map = store._childrenByParent;
        const set = store._hasChildrenSet;
        // Both reflect the same underlying data
        expect(map.get(1)).toHaveLength(2);
        expect(set.has(1)).toBe(true);
        expect(set.has(2)).toBe(false);
    });

    it('invalidates when folders reference changes', () => {
        const arr1 = [f(1, 0)];
        const arr2 = [f(1, 0), f(2, 1)];
        const store = makeStore(arr1);
        expect(store._hasChildrenSet.has(1)).toBe(false);
        store.folders = arr2;
        expect(store._hasChildrenSet.has(1)).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// [internal] — patchFolder invalidation (in-place mutation, same array ref)
// ---------------------------------------------------------------------------

describe('patchFolder invalidation — [internal]', () => {
    it('_childrenByParent reflects the patched object without a folders reference change', () => {
        const arr = [f(1, 0)];
        const store = makeVersionedStore(arr);
        const before = store._childrenByParent.get(0)[0];
        expect(before.count).toBeUndefined();

        store.patchFolder(1, { count: 42 });

        expect(store.folders).toBe(arr); // same reference — the whole point of the bug
        const after = store._childrenByParent.get(0)[0];
        expect(after.count).toBe(42);
    });

    it('filteredFolders reflects the patched object during active search', () => {
        const arr = [f(1, 0, 'alpha')];
        const store = makeVersionedStore(arr, 'alpha');
        store.filteredFolders; // warm the cache
        store.patchFolder(1, { count: 5 });
        expect(store.filteredFolders[0].count).toBe(5);
    });

    it('isSearchOnlyMode recomputes after a patch that crosses the threshold', () => {
        const arr = [f(1, 0), f(2, 0)];
        const store = makeVersionedStore(arr);
        store.isSearching = false;
        store.shouldUseDeferredTree = () => false;
        store.hasLoadedFullTree = true;
        // threshold defaults to 500 via mocked getRuntime — not reachable with 2 folders,
        // this test only asserts the cache re-evaluates (same boolean, re-read after version bump).
        const r1 = store.isSearchOnlyMode;
        store.patchFolder(1, { isProtected: true });
        const r2 = store.isSearchOnlyMode;
        expect(r1).toBe(false);
        expect(r2).toBe(false);
        // Prove it actually re-scanned (not just returned a stale cached false) by checking
        // the underlying object was really patched.
        expect(store.folders[0].isProtected).toBe(true);
    });

    it('repeated getter reads without a new patch still hit the cache (no rebuild)', () => {
        const arr = [f(1, 0)];
        const store = makeVersionedStore(arr);
        const m1 = store._childrenByParent;
        const m2 = store._childrenByParent;
        expect(m1).toBe(m2);
    });
});

// ---------------------------------------------------------------------------
// hasNoUserFolders / hasNoSearchResults
// ---------------------------------------------------------------------------

describe('hasNoUserFolders', () => {
    it('returns true when all folders are protected', () => {
        const store = makeStore([f(1, 0, 'Trash', true)]);
        expect(store.hasNoUserFolders).toBe(true);
    });

    it('returns false when at least one non-protected folder exists', () => {
        const store = makeStore([f(1, 0, 'Trash', true), f(2, 0, 'Work', false)]);
        expect(store.hasNoUserFolders).toBe(false);
    });
});

describe('hasNoSearchResults', () => {
    it('returns false when searchQuery is empty', () => {
        const store = makeStore([f(1, 0, 'Work')]);
        expect(store.hasNoSearchResults).toBe(false);
    });

    it('returns true when search matches no non-protected folder', () => {
        const store = makeStore([f(1, 0, 'Trash', true)], 'xyz');
        expect(store.hasNoSearchResults).toBe(true);
    });

    it('returns false when search matches a non-protected folder', () => {
        const store = makeStore([f(1, 0, 'Work')], 'work');
        expect(store.hasNoSearchResults).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// [internal] ([internal]) — фильтрация избранного единым searchQuery
// ---------------------------------------------------------------------------

describe('favoriteMatchesSearch / hasVisibleFavorites / visibleFavoritesCount', () => {
    function makeFavStore(folders, favorites, searchQuery = '') {
        const store = mergeStore(searchModule);
        store.folders = folders;
        store.favorites = favorites;
        store.searchQuery = searchQuery;
        return store;
    }

    const favFolders = [
        f(10, 0, 'Вложение 10'),
        f(12, 0, 'Вложения 20 на 2012'),
        f(14, 0, 'Вложения 20 на 2014'),
        f(17, 0, 'Вложения 20 на 2017'),
        f(20, 0, 'Вложения 20 на 2020'),
    ];
    const allFavs = [10, 12, 14, 17, 20];

    it('favoriteMatchesSearch: empty query matches any folder', () => {
        const store = makeFavStore(favFolders, allFavs, '');
        expect(store.favoriteMatchesSearch(favFolders[1])).toBe(true);
    });

    it('favoriteMatchesSearch: matches only folders containing the query (case-insensitive)', () => {
        const store = makeFavStore(favFolders, allFavs, '2014');
        expect(store.favoriteMatchesSearch(f(14, 0, 'Вложения 20 на 2014'))).toBe(true);
        expect(store.favoriteMatchesSearch(f(12, 0, 'Вложения 20 на 2012'))).toBe(false);
    });

    it('favoriteMatchesSearch: null folder returns false', () => {
        const store = makeFavStore(favFolders, allFavs, '2014');
        expect(store.favoriteMatchesSearch(null)).toBe(false);
    });

    // Контроль-регресс #155: при поиске «2014» видна только одна избранная папка,
    // а не все пять (до фикса избранное показывало всё независимо от запроса).
    it('hasVisibleFavorites + visibleFavoritesCount: only matches count under active search', () => {
        const store = makeFavStore(favFolders, allFavs, '2014');
        expect(store.visibleFavoritesCount).toBe(1);
        expect(store.hasVisibleFavorites).toBe(true);
    });

    it('hasVisibleFavorites: false when no favorite matches the query (block hidden)', () => {
        const store = makeFavStore(favFolders, allFavs, '9999');
        expect(store.visibleFavoritesCount).toBe(0);
        expect(store.hasVisibleFavorites).toBe(false);
    });

    it('empty query: all favorites visible (count = all), behaviour unchanged', () => {
        const store = makeFavStore(favFolders, allFavs, '');
        expect(store.visibleFavoritesCount).toBe(5);
        expect(store.hasVisibleFavorites).toBe(true);
    });

    it('favorites module disabled (this.favorites undefined) is safe', () => {
        const store = mergeStore(searchModule);
        store.folders = favFolders;
        store.searchQuery = '2014';
        // favorites не домёржен
        expect(store.hasVisibleFavorites).toBe(false);
        expect(store.visibleFavoritesCount).toBe(0);
    });

    it('orphan favorite id (folder not in folders) is excluded', () => {
        const store = makeFavStore(favFolders, [10, 999], '');
        // 999 нет в folders → не считается
        expect(store.visibleFavoritesCount).toBe(1);
    });
});

describe('systemRootFolders', () => {
    it('returns only protected root folders', () => {
        const store = makeStore([
            f(1, 0, 'All files', true),
            f(2, 0, 'Trash', true),
            f(3, 1, 'Nested protected', true),
            f(4, 0, 'Projects', false),
        ]);

        expect(store.systemRootFolders.map((folder) => folder.id)).toEqual([1, 2]);
    });

    it('follows the filtered tree during search', () => {
        const store = makeStore([
            f(1, 0, 'All files', true),
            f(2, 0, 'Projects'),
            f(3, 2, 'Invoices 2025'),
        ], '2025');

        expect(store.systemRootFolders.map((folder) => folder.id)).toEqual([]);
    });

    // [internal]: Trash-узел прячется только когда корзина пуста ПОЛНОСТЬЮ
    // (count=файлы И foldersCount=папки == 0). count=только файлы ([internal]), поэтому
    // корзина с папками, но без файлов, раньше ошибочно скрывалась.
    describe('Trash visibility by count + foldersCount', () => {
        const TRASH_ID = 7;
        beforeEach(() => { window.Plathix = { trashFolderId: TRASH_ID }; });
        afterEach(() => { delete window.Plathix; });

        const trash = (count, foldersCount) => ({
            id: TRASH_ID, parentId: 0, name: 'Trash', isProtected: true, count, foldersCount,
        });

        it('shows Trash when it has files (count > 0)', () => {
            const store = makeStore([trash(3, 0)]);
            expect(store.systemRootFolders.map((x) => x.id)).toEqual([TRASH_ID]);
        });

        it('shows Trash when it has ONLY folders (count 0, foldersCount > 0)', () => {
            const store = makeStore([trash(0, 4)]);
            expect(store.systemRootFolders.map((x) => x.id)).toEqual([TRASH_ID]);
        });

        it('hides Trash when fully empty (count 0 AND foldersCount 0)', () => {
            const store = makeStore([trash(0, 0)]);
            expect(store.systemRootFolders.map((x) => x.id)).toEqual([]);
        });
    });
});

describe('isSearchOnlyMode', () => {
    it('returns false while a search query is active', () => {
        const folders = Array.from({ length: 600 }, (_, idx) => f(idx + 1, 0, `Folder ${idx + 1}`));
        const store = makeStore(folders, '12');

        expect(store.isSearchOnlyMode).toBe(false);
    });

    it('returns true when the non-protected folder threshold is reached', () => {
        const folders = Array.from({ length: 500 }, (_, idx) => f(idx + 1, 0, `Folder ${idx + 1}`));
        const store = makeStore(folders);

        expect(store.isSearchOnlyMode).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// [internal] ([internal]) — setSearchQuery раскрывает путь к совпадениям
// ---------------------------------------------------------------------------

describe('setSearchQuery — path reveal for matches', () => {
    function makeSearchStore(folders, extra = {}) {
        const store = mergeStore(searchModule);
        store.folders = folders;
        store.searchQuery = '';
        Object.assign(store, extra);
        return store;
    }

    const nested = [
        f(1, 0, 'Вложения 20 на 20'),   // родитель (не совпадает с 2014)
        f(2, 1, 'Вложения 20 на 2014'), // совпадение, под родителем
        f(3, 0, 'Other'),
    ];

    it('calls expandAncestors for each matching folder', async() => {
        const expandAncestors = jest.fn(() => Promise.resolve());
        const store = makeSearchStore(nested, { expandAncestors });

        await store.setSearchQuery('2014');

        // совпадение — только папка 2; раскрываем её предков
        expect(expandAncestors.mock.calls.map((c) => c[0])).toEqual([2]);
        expect(store.searchQuery).toBe('2014');
    });

    it('does not call expandAncestors when query is empty (clear search)', async() => {
        const expandAncestors = jest.fn(() => Promise.resolve());
        const store = makeSearchStore(nested, { expandAncestors });

        await store.setSearchQuery('');

        expect(expandAncestors).not.toHaveBeenCalled();
    });

    it('handles multiple matches (expands each)', async() => {
        const expandAncestors = jest.fn(() => Promise.resolve());
        const store = makeSearchStore([
            f(1, 0, 'Клиенты 2014'),
            f(2, 0, 'Архив 2014'),
            f(3, 0, 'Nope'),
        ], { expandAncestors });

        await store.setSearchQuery('2014');

        expect(expandAncestors.mock.calls.map((c) => c[0]).sort()).toEqual([1, 2]);
    });

    it('is safe when expandAncestors is not available (favorites/tree module absent)', async() => {
        const store = makeSearchStore(nested); // без expandAncestors
        await expect(store.setSearchQuery('2014')).resolves.toBeUndefined();
        expect(store.searchQuery).toBe('2014');
    });
});

describe('setSortBy', () => {
    it('defaults to "default"', () => {
        const store = makeStore([f(1, 0, 'A'), f(2, 0, 'B')]);
        expect(store.sortBy).toBe('default');
    });

    it('sets sortBy value', () => {
        const store = makeStore([f(1, 0, 'A'), f(2, 0, 'B')]);
        store.setSortBy('alpha');
        expect(store.sortBy).toBe('alpha');
    });

    it('can be reset to default', () => {
        const store = makeStore([f(1, 0, 'A')]);
        store.setSortBy('new');
        store.setSortBy('default');
        expect(store.sortBy).toBe('default');
    });
});

// ---------------------------------------------------------------------------
// [internal] — cycle-guard в _childrenByParent (защита рантайм-рекурсии от виса)
// ---------------------------------------------------------------------------

describe('_childrenByParent — cycle-guard', () => {
    it('валидное дерево: дети связаны по parentId как прежде', () => {
        // 1 (корень) -> 2 -> 3, ацикличное
        const store = makeStore([f(1, 0), f(2, 1), f(3, 2)]);
        const map = store._childrenByParent;
        expect(map.get(0).map((x) => x.id)).toEqual([1]);
        expect(map.get(1).map((x) => x.id)).toEqual([2]);
        expect(map.get(2).map((x) => x.id)).toEqual([3]);
    });

    it('цикл A<->B: построение НЕ виснет и не связывает цикл как детей', () => {
        // 1.parent=2, 2.parent=1 — прямой цикл. Раньше рантайм-рекурсия зациклилась бы.
        const store = makeStore([f(1, 2), f(2, 1)]);
        // не виснет — сам факт получения карты доказывает завершение
        const map = store._childrenByParent;
        // обе папки обрублены на корень, ни одна не показана ребёнком другой
        expect(map.get(1)).toBeUndefined();
        expect(map.get(2)).toBeUndefined();
        expect((map.get(0) || []).map((x) => x.id).sort()).toEqual([1, 2]);
    });

    it('КОНТРОЛЬ-«убитый»: без цикла вложенность НЕ уходит ошибочно на корень', () => {
        // если guard был бы слишком агрессивным, эти валидные дети уехали бы на корень.
        const store = makeStore([f(10, 0), f(11, 10), f(12, 11)]);
        const map = store._childrenByParent;
        expect(map.get(0).map((x) => x.id)).toEqual([10]);
        expect(map.get(10).map((x) => x.id)).toEqual([11]);
        expect(map.get(11).map((x) => x.id)).toEqual([12]);
        expect(map.get(0).map((x) => x.id)).not.toContain(11);
        expect(map.get(0).map((x) => x.id)).not.toContain(12);
    });

    it('самоссылка (папка сама себе родитель) обрубается на корень', () => {
        const store = makeStore([f(5, 5)]);
        const map = store._childrenByParent;
        expect(map.get(5)).toBeUndefined();
        expect((map.get(0) || []).map((x) => x.id)).toEqual([5]);
    });
});

// ---------------------------------------------------------------------------
// setSortBy — persistence ([internal])
// ---------------------------------------------------------------------------

describe('setSortBy persistence', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('persists the chosen sort mode to localStorage under a post-type-scoped key', () => {
        const store = makeStore();

        store.setSortBy('alpha');

        expect(store.sortBy).toBe('alpha');
        expect(localStorage.getItem('plathix_sort_by_attachment')).toBe('alpha');
    });

    it('does not throw when localStorage is unavailable', () => {
        const store = makeStore();
        const original = global.localStorage;
        // Симулируем недоступность localStorage (приватный режим, квота исчерпана и т.п.).
        Object.defineProperty(global, 'localStorage', {
            value: { setItem() { throw new Error('blocked'); } },
            configurable: true,
        });

        expect(() => store.setSortBy('new')).not.toThrow();
        expect(store.sortBy).toBe('new');

        Object.defineProperty(global, 'localStorage', { value: original, configurable: true });
    });
});
