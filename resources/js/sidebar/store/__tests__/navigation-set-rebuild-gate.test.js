


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
    getRuntime: jest.fn(() => ({ trashFolderId: 999, deferFoldersBootstrap: false })),
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


const TWO_BRANCHES = [
    { id: 1, name: 'A', parentId: 0, hasChildren: true },
    { id: 5, name: 'B', parentId: 0, hasChildren: true },
];

function makeStore(extraState = {})
{
    const base = mergeStore(navigationModule, treeStateModule);
    return Object.assign(Object.create(null), base, {
        folders: [...TWO_BRANCHES],
        openId: 0,
        isLoading: false,
        error: null,
        applyFolderFilter: jest.fn(),
        notify: jest.fn(),


        hasLoadedFullTree: true,
        loadedParentIds: new Set([0, 1, 5]),
        ...extraState,
    });
}

describe('refreshes cached state when data changes', () => {
    beforeEach(() => jest.clearAllMocks());



    it('preserves folder tree behavior', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { parent_id: 1 } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });


    it('covers public behavior without internal references', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { search: 'A' } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    it('covers public behavior without internal references', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { ids: [1] } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });



    it('preserves folder tree behavior', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 5, 777]) });
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES });

        await store.refreshFolders({ silent: true });


        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });



    it('covers public behavior without internal references', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({
            silent: true,
            replace: false,
            params: { parent_id: 1 },
        });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });
});

describe('refreshes cached state when data changes', () => {
    beforeEach(() => jest.clearAllMocks());



    it('preserves folder tree behavior', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES, fullTree: true });

        await store.refreshFolders({ silent: true, params: { search: 'A' } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });



    it('preserves folder tree behavior', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]], fullTree: false });

        await store.refreshFolders({ silent: true, params: {} });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });



    it('preserves folder tree behavior', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 5, 777]) });
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES });

        await store.refreshFolders({ silent: true });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });
});
