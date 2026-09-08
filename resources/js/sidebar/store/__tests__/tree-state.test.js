import { treeStateModule } from '../tree-state.js';
import { mergeStore } from '../utils.js';

jest.mock('../../runtime.js', () => ({
    getRuntime: () => ({}),
}));

function f(id, extra = {}) {
    return { id, name: `f${id}`, parentId: 0, color: null, position: 0, hasChildren: false, ...extra };
}

function makeStore(folders = []) {
    const store = mergeStore(treeStateModule);
    store.folders = folders;
    return store;
}

describe('patchFolder', () => {
    it('replaces the matching element in place, preserving other fields', () => {
        const arr = [f(1, { count: 3, color: 'red' }), f(2, { count: 7 })];
        const store = makeStore(arr);
        const ok = store.patchFolder(1, { count: 9 });
        expect(ok).toBe(true);
        expect(store.folders).toBe(arr); // same array reference — in-place splice
        expect(store.folders[0]).toEqual({ ...f(1, { count: 3, color: 'red' }), count: 9 });
        expect(store.folders[1].count).toBe(7); // untouched sibling
    });

    it('returns false and does not mutate when id is not found', () => {
        const arr = [f(1)];
        const store = makeStore(arr);
        const ok = store.patchFolder(999, { count: 5 });
        expect(ok).toBe(false);
        expect(store.folders).toEqual(arr);
    });

    it('matches id loosely via Number() (string vs number id)', () => {
        const arr = [f(1, { count: 1 })];
        const store = makeStore(arr);
        const ok = store.patchFolder('1', { count: 2 });
        expect(ok).toBe(true);
        expect(store.folders[0].count).toBe(2);
    });

    it('increments foldersVersion on every successful patch', () => {
        const store = makeStore([f(1)]);
        const v0 = store.foldersVersion;
        store.patchFolder(1, { count: 1 });
        expect(store.foldersVersion).toBe(v0 + 1);
        store.patchFolder(1, { count: 2 });
        expect(store.foldersVersion).toBe(v0 + 2);
    });

    it('does not increment foldersVersion when the target id is not found', () => {
        const store = makeStore([f(1)]);
        const v0 = store.foldersVersion;
        store.patchFolder(999, { count: 1 });
        expect(store.foldersVersion).toBe(v0);
    });
});

describe('mergeFolders', () => {
    it('increments foldersVersion when replacing the folders array', () => {
        const store = makeStore([f(1)]);
        const v0 = store.foldersVersion;
        store.mergeFolders([f(2)]);
        expect(store.foldersVersion).toBe(v0 + 1);
    });
});
