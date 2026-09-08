


import { navigationModule } from '../navigation.js';
import { treeStateModule } from '../tree-state.js';
import { mergeStore } from '../utils.js';

jest.mock('alpinejs', () => ({
    nextTick: jest.fn((cb) => cb()),
}));

jest.mock('../../api.js', () => ({
    Api: {
        getFolders: jest.fn(),
        getFolderCount: jest.fn(),
        savePreference: jest.fn(),
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../hooks.js', () => ({
    doAction: jest.fn(),
}));



jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(() => null),
    getPostType: jest.fn(() => 'attachment'),
    getRuntime: jest.fn(() => ({ trashFolderId: 999, deferFoldersBootstrap: true })),
    shouldUseStaticListFiltering: jest.fn(() => false),
    shouldUseMediaFrameFiltering: jest.fn(() => false),
}));

jest.mock('../../static-list/index.js', () => ({
    getStaticListManager: jest.fn(() => null),
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheClear: jest.fn(),
}));

import { Api } from '../../api.js';

const FULL_TREE = [
    { id: 1, name: 'A', parentId: 0, hasChildren: true },
    { id: 2, name: 'B', parentId: 1, hasChildren: false },
];

function makeStore(extraState = {})
{
    const base = mergeStore(navigationModule, treeStateModule);
    return Object.assign(Object.create(null), base, {
        folders: [],
        openId: 0,
        isLoading: false,
        error: null,
        applyFolderFilter: jest.fn(),
        notify: jest.fn(),


        hasLoadedFullTree: false,
        loadedParentIds: new Set([0]),
        ...extraState,
    });
}


function deferred()
{
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });
    return { promise, resolve };
}

describe('refreshes cached state when data changes', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        Api.getFolders.mockResolvedValue({ folders: FULL_TREE });
    });



    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });

        expect(store.folders).toEqual(FULL_TREE);
        expect(store.hasLoadedFullTree).toBe(true);
    });




    it('preserves folder tree behavior', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });
        Api.getFolders.mockClear();
        const result = await store.loadFolderChildren(1);

        expect(result).toBeNull();
        expect(Api.getFolders).not.toHaveBeenCalled();
    });



    it('preserves folder tree behavior', async() => {
        const store = makeStore();

        await store.loadFolderChildren(1);

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('preserves folder tree behavior', async() => {
        const store = makeStore({ hasLoadedFullTree: true });

        await store.refreshFolders({ silent: true, markFullTree: false });

        expect(store.hasLoadedFullTree).toBe(false);
    });






    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { search: 'foo' } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { ids: [1, 2] } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { fields: 'id,count' } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('preserves folder tree behavior', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, replace: true, params: { parent_id: 1 } });

        expect(store.hasLoadedFullTree).toBe(false);
    });




    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({
            silent: true,
            params: { search: '', ids: [], fields: undefined },
        });

        expect(store.hasLoadedFullTree).toBe(true);
    });

    it('covers public behavior without internal references', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, replace: false });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('covers public behavior without internal references', async() => {

        const store = makeStore({ hasLoadedFullTree: true });

        await store.refreshFolders({ silent: true });

        expect(store.hasLoadedFullTree).toBe(true);
    });

    it('preserves folder tree behavior', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });


        expect([...store.loadedParentIds].sort()).toEqual([0, 1]);
    });





    it('preserves folder tree behavior', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 999]) });

        await store.refreshFolders({ silent: true });


        expect([...store.loadedParentIds].sort()).toEqual([0, 1]);
    });





    it('preserves folder tree behavior', async() => {
        const store = makeStore();
        const slowFull = deferred();

        Api.getFolders.mockImplementationOnce(() => slowFull.promise);
        const fullPromise = store.loadCompleteFolderTree({ silent: true });

        Api.getFolders.mockResolvedValueOnce({ folders: [{ id: 9, name: 'C', parentId: 0 }] });
        await store.loadFolderChildren(5);

        const afterPartialFlag = store.hasLoadedFullTree;
        const afterPartialIds = [...store.loadedParentIds].sort();

        slowFull.resolve({ folders: FULL_TREE });
        await fullPromise;

        expect(store.hasLoadedFullTree).toBe(afterPartialFlag);
        expect([...store.loadedParentIds].sort()).toEqual(afterPartialIds);
    });
});
