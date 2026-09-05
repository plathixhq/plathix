jest.mock('../../runtime.js', () => ({
    getPostType: () => 'attachment',
}));

import { foldersTreeModule } from '../folders-tree.js';
import { mergeStore } from '../utils.js';

function makeStore(extraState = {}) {
    const base = mergeStore(foldersTreeModule);
    return Object.assign(Object.create(null), base, {
        folders: [],
        collapsedIds: {},
        newFolderParentId: null,
        newFolderName: '',
        folderSelectMode: false,
        folderDragMode: false,
        selectedFolderIds: [],
        _selectedFolderIdsSet: new Set(),
        _hasChildrenSet: new Set(),
        canManage: true,
        hideNewFolderForm: jest.fn(),
        ...extraState,
    });
}

describe('foldersTreeModule', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('collapseAll collapses every folder that actually has children, including protected roots', () => {
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0, isProtected: true },
                { id: 2, parentId: 1, isProtected: false },
                { id: 3, parentId: 2, isProtected: false },
                { id: 4, parentId: 0, isProtected: false },
            ],
            _hasChildrenSet: new Set([1, 2]),
        });

        store.collapseAll();

        expect(store.collapsedIds).toEqual({
            1: true,
            2: true,
        });
    });

    it('toggleExpandAll expands tree when every collapsible node is already collapsed', () => {
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0, isProtected: true },
                { id: 2, parentId: 1, isProtected: false },
                { id: 3, parentId: 2, isProtected: false },
            ],
            _hasChildrenSet: new Set([1, 2]),
            collapsedIds: {
                1: true,
                2: true,
            },
        });

        store.toggleExpandAll();

        expect(store.collapsedIds).toEqual({});
    });

    it('toggleExpandAll collapses tree when at least one collapsible node is expanded', () => {
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0, isProtected: true },
                { id: 2, parentId: 1, isProtected: false },
                { id: 3, parentId: 2, isProtected: false },
            ],
            _hasChildrenSet: new Set([1, 2]),
            collapsedIds: {
                2: true,
            },
        });

        store.toggleExpandAll();

        expect(store.collapsedIds).toEqual({
            1: true,
            2: true,
        });
    });
});

describe('foldersTreeModule — expandAncestors ([internal], [internal])', () => {
    // дерево: 1(root) → 2 → 3(целевая, в «Избранном»)
    const nestedFolders = [
        { id: 1, parentId: 0 },
        { id: 2, parentId: 1 },
        { id: 3, parentId: 2 },
    ];

    beforeEach(() => {
        localStorage.clear();
    });

    it('full-tree: removes all ancestors from collapsedIds (delete form, no orphan false keys)', () => {
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: { 1: true, 2: true },
            shouldUseDeferredTree: () => false,
        });

        return store.expandAncestors(3).then(() => {
            // предки 1 и 2 раскрыты и УДАЛЕНЫ (не false), чтобы не мусорить persist/collapseAll
            expect(store.collapsedIds).toEqual({});
            expect(Object.prototype.hasOwnProperty.call(store.collapsedIds, '1')).toBe(false);
            expect(Object.prototype.hasOwnProperty.call(store.collapsedIds, '2')).toBe(false);
        });
    });

    it('persists collapsedIds to localStorage after expanding', () => {
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: { 1: true, 2: true },
            shouldUseDeferredTree: () => false,
        });

        return store.expandAncestors(3).then(() => {
            expect(JSON.parse(localStorage.getItem('plathix_collapsed_attachment'))).toEqual({});
        });
    });

    it('root folder (no ancestors) is a no-op and does not touch loadFolderChildren', () => {
        const loadFolderChildren = jest.fn();
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: { 1: true },
            shouldUseDeferredTree: () => true,
            hasLoadedChildren: () => false,
            loadFolderChildren,
        });

        return store.expandAncestors(1).then(() => {
            expect(store.collapsedIds).toEqual({ 1: true }); // не трогаем сам узел/чужие ключи
            expect(loadFolderChildren).not.toHaveBeenCalled();
        });
    });

    it('deferred: loads children of every ancestor by chain (root → folder order)', () => {
        const calls = [];
        const loadFolderChildren = jest.fn((id) => { calls.push(id); return Promise.resolve(); });
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: {},
            shouldUseDeferredTree: () => true,
            hasLoadedChildren: () => false, // ничего не догружено
            loadFolderChildren,
        });

        return store.expandAncestors(3).then(() => {
            // догрузка предков 3 → [1, 2], именно в порядке от корня к папке
            expect(calls).toEqual([1, 2]);
        });
    });

    it('deferred: skips ancestors whose children are already loaded (idempotent)', () => {
        const loadFolderChildren = jest.fn(() => Promise.resolve());
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: {},
            shouldUseDeferredTree: () => true,
            hasLoadedChildren: (id) => Number(id) === 1, // корень уже загружен
            loadFolderChildren,
        });

        return store.expandAncestors(3).then(() => {
            expect(loadFolderChildren).toHaveBeenCalledTimes(1);
            expect(loadFolderChildren).toHaveBeenCalledWith(2, { silent: true });
        });
    });

    // Контроль-регресс: доказывает, что баг #154 реально был.
    // На поведении «до фикса» (без expandAncestors) предки остаются свёрнутыми.
    // Здесь фиксируем инвариант обратной стороны: без вызова expandAncestors
    // collapsedIds не меняется — то есть именно expandAncestors устраняет невидимость.
    it('regression control: WITHOUT expandAncestors ancestors stay collapsed (proves the bug)', () => {
        const store = makeStore({
            folders: nestedFolders,
            collapsedIds: { 1: true, 2: true },
            shouldUseDeferredTree: () => false,
        });

        // имитация «старого» openFolder: только выбор, без раскрытия предков
        store.openId = 3;

        // ветка предков осталась свёрнутой → узел 3 не был бы отрисован (баг #154)
        expect(store.collapsedIds).toEqual({ 1: true, 2: true });

        // после фикса — раскрывается
        return store.expandAncestors(3).then(() => {
            expect(store.collapsedIds).toEqual({});
        });
    });
});

describe('foldersTreeModule — _selectedFolderIdsSet sync', () => {
    it('toggleFolderSelected adds id to both selectedFolderIds and _selectedFolderIdsSet', () => {
        const store = makeStore();
        store.toggleFolderSelected(3);
        expect(store.selectedFolderIds).toContain(3);
        expect(store._selectedFolderIdsSet.has(3)).toBe(true);
    });

    it('toggleFolderSelected removes id from both on second call', () => {
        const store = makeStore();
        store.toggleFolderSelected(3);
        store.toggleFolderSelected(3);
        expect(store.selectedFolderIds).not.toContain(3);
        expect(store._selectedFolderIdsSet.has(3)).toBe(false);
    });

    it('isFolderSelected returns true for selected id and false for others', () => {
        const store = makeStore();
        store.toggleFolderSelected(5);
        expect(store.isFolderSelected(5)).toBe(true);
        expect(store.isFolderSelected(6)).toBe(false);
    });

    it('multiple selections stay in sync across array and Set', () => {
        const store = makeStore();
        store.toggleFolderSelected(1);
        store.toggleFolderSelected(2);
        store.toggleFolderSelected(3);
        expect(store.selectedFolderIds).toEqual([1, 2, 3]);
        expect(store._selectedFolderIdsSet.size).toBe(3);
        store.toggleFolderSelected(2);
        expect(store.selectedFolderIds).toEqual([1, 3]);
        expect(store._selectedFolderIdsSet.has(2)).toBe(false);
        expect(store._selectedFolderIdsSet.size).toBe(2);
    });

    it('toggleFolderSelectMode resets both selectedFolderIds and _selectedFolderIdsSet', () => {
        const store = makeStore({ folderSelectMode: true, newFolderParentId: null, newFolderName: '' });
        store.toggleFolderSelected(5);
        store.toggleFolderSelected(6);
        store.toggleFolderSelectMode();
        expect(store.selectedFolderIds).toEqual([]);
        expect(store._selectedFolderIdsSet.has(5)).toBe(false);
        expect(store._selectedFolderIdsSet.has(6)).toBe(false);
        expect(store._selectedFolderIdsSet.size).toBe(0);
    });

    it('toggleFolderDragMode resets both selectedFolderIds and _selectedFolderIdsSet when entering drag mode', () => {
        const store = makeStore({ folderDragMode: false, folderSelectMode: false });
        store.toggleFolderSelected(7);
        store.toggleFolderDragMode();
        expect(store.selectedFolderIds).toEqual([]);
        expect(store._selectedFolderIdsSet.has(7)).toBe(false);
        expect(store._selectedFolderIdsSet.size).toBe(0);
    });
});
