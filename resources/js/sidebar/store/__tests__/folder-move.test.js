import { folderMoveModule } from '../folder-move.js';
import { notificationsModule } from '../notifications.js';
import { mergeStore } from '../utils.js';
import { makeBaseStore } from '../test-base.js';
import { Api } from '../../api.js';
import { getDepthLimit } from '../../runtime.js';

jest.mock('../../api.js', () => ({
    Api: {
        moveFolderParent: jest.fn(),
        moveFolderToSiblingOf: jest.fn(),
    },
}));

jest.mock('../../events.js', () => ({
    Events: { FOLDER_MOVED: 'folder_moved' },
}));

jest.mock('../../runtime.js', () => ({
    getDepthLimit: jest.fn(() => 3),
    getRuntime: jest.fn(() => ({})),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

import { cacheInvalidateFolder } from '../../static-list/cache.js';

function makeStore(extraState = {}) {
    const base = mergeStore(makeBaseStore(), notificationsModule, folderMoveModule);
    return Object.assign(Object.create(null), base, {
        notifications: [],
        _notifId: 0,
        folders: [],
        async refreshFolders() {},
        ...extraState,
    });
}

describe('folderMoveModule — getFolderDepth', () => {
    it('returns 1 for a root-level folder', () => {
        const store = makeStore({
            folders: [{ id: 1, parentId: 0 }],
        });
        expect(store.getFolderDepth(1)).toBe(1);
    });

    it('returns 2 for a folder nested two levels deep', () => {
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0 },
                { id: 2, parentId: 1 },
            ],
        });
        expect(store.getFolderDepth(2)).toBe(2);
    });

    it('returns 3 for a folder nested three levels deep', () => {
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0 },
                { id: 2, parentId: 1 },
                { id: 3, parentId: 2 },
            ],
        });
        expect(store.getFolderDepth(3)).toBe(3);
    });

    it('terminates without throwing when a folder references itself as parent', () => {
        const store = makeStore({
            folders: [{ id: 5, parentId: 5 }],
        });
        expect(() => store.getFolderDepth(5)).not.toThrow();
        expect(store.getFolderDepth(5)).toBe(1);
    });

    it('terminates without throwing on a longer cycle (A → B → A)', () => {
        const store = makeStore({
            folders: [
                { id: 10, parentId: 11 },
                { id: 11, parentId: 10 },
            ],
        });
        expect(() => store.getFolderDepth(10)).not.toThrow();
    });

    it('returns 0 when the folder id is not present in the folders array', () => {
        const store = makeStore({ folders: [] });
        expect(store.getFolderDepth(999)).toBe(0);
    });
});

describe('folderMoveModule — canCreateChild', () => {
    it('allows creation when parent depth is below the configured limit', () => {
        const store = makeStore();
        expect(store.canCreateChild(2)).toBe(true);
    });

    it('blocks creation when parent depth equals the configured limit', () => {
        const store = makeStore();
        expect(store.canCreateChild(3)).toBe(false);
    });

    it('blocks creation when parent depth exceeds the configured limit', () => {
        const store = makeStore();
        expect(store.canCreateChild(4)).toBe(false);
    });

    it('allows creation at any depth when limit is 0 (unlimited)', () => {
        getDepthLimit.mockReturnValueOnce(0);
        const store = makeStore();
        expect(store.canCreateChild(999)).toBe(true);
    });
});

describe('folderMoveModule — moveFolderToSiblingOf drag-sort hint ([internal])', () => {
    beforeEach(() => {
        Api.moveFolderToSiblingOf.mockResolvedValue({});
    });

    it('shows an info notice when reordering while a non-default sort is active', async() => {
        const store = makeStore({ sortBy: 'alpha' });

        await store.moveFolderToSiblingOf(10, 0, 2);

        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0].type).toBe('info');
        expect(store.notifications[0].key).toBe('drag-sort-hint');
    });

    it('does not show the notice when sorting is "default"', async() => {
        const store = makeStore({ sortBy: 'default' });

        await store.moveFolderToSiblingOf(10, 0, 2);

        expect(store.notifications).toHaveLength(0);
    });

    it('does not show the notice when sortBy is unset', async() => {
        const store = makeStore({ sortBy: null });

        await store.moveFolderToSiblingOf(10, 0, 2);

        expect(store.notifications).toHaveLength(0);
    });

    it('replaces a previous hint instead of stacking duplicates on repeated drags', async() => {
        const store = makeStore({ sortBy: 'alpha' });

        await store.moveFolderToSiblingOf(10, 0, 2);
        await store.moveFolderToSiblingOf(11, 0, 3);

        expect(store.notifications).toHaveLength(1);
    });
});

describe('folderMoveModule — moveFolderToParent does not show the drag-sort hint ([internal])', () => {
    it('never shows the notice, regardless of sortBy, since this is not a sibling-reorder', async() => {
        Api.moveFolderParent.mockResolvedValue({});
        const store = makeStore({ sortBy: 'alpha' });

        await store.moveFolderToParent(10, 5);

        expect(store.notifications).toHaveLength(0);
    });
});

describe('folderMoveModule — point-wise cache invalidation ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        Api.moveFolderParent.mockResolvedValue({});
        Api.moveFolderToSiblingOf.mockResolvedValue({});
    });

    it('moveFolderToParent invalidates moved folder, new parent, and old parent', async() => {
        const refreshFolders = jest.fn().mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 10, parentId: 3 }],
            refreshFolders,
        });

        await store.moveFolderToParent(10, 5);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(10);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(5);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(3);
        expect(refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('moveFolderToParent does not duplicate invalidation when old and new parent are the same', async() => {
        const refreshFolders = jest.fn().mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 10, parentId: 5 }],
            refreshFolders,
        });

        await store.moveFolderToParent(10, 5);

        expect(cacheInvalidateFolder).toHaveBeenCalledTimes(2);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(10);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(5);
    });

    it('moveFolderToSiblingOf invalidates moved folder, target parent, and old parent', async() => {
        const refreshFolders = jest.fn().mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 10, parentId: 3 }],
            refreshFolders,
        });

        await store.moveFolderToSiblingOf(10, 7, 2);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(10);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(7);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(3);
        expect(refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });
});

describe('folderMoveModule — refreshFolders failure does not swallow independent side-effects ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        Api.moveFolderParent.mockResolvedValue({});
        Api.moveFolderToSiblingOf.mockResolvedValue({});
    });

    it('moveFolderToParent still dispatches FOLDER_MOVED when refreshFolders rejects', async () => {
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [{ id: 10, parentId: 3 }],
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
        });

        await store.moveFolderToParent(10, 5);

        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('folder_moved');
        dispatchSpy.mockRestore();
    });

    it('moveFolderToSiblingOf still dispatches FOLDER_MOVED and shows the sort hint when refreshFolders rejects', async () => {
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [{ id: 10, parentId: 3 }],
            sortBy: 'alpha',
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
        });

        await store.moveFolderToSiblingOf(10, 7, 2);

        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('folder_moved');
        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0].type).toBe('info');
        dispatchSpy.mockRestore();
    });
});
