import { foldersCrudModule } from '../folders-crud.js';
import { notificationsModule } from '../notifications.js';
import { foldersTreeModule } from '../folders-tree.js';
import { makeBaseStore } from '../test-base.js';
import { mergeStore } from '../utils.js';

jest.mock('../../api.js', () => ({
    Api: {
        createFolder: jest.fn(),
        getFolder: jest.fn(),
        renameFolder: jest.fn(),
        deleteFolder: jest.fn(),
    },
    DEFAULT_ON_CHILDREN: 'delete',
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(() => ({ trashFolderId: 77 })),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

jest.mock('../../media-grid-cache.js', () => ({
    memClear: jest.fn(),
}));

import { Api } from '../../api.js';
import { cacheInvalidateFolder } from '../../static-list/cache.js';
import { memClear } from '../../media-grid-cache.js';

function makeStore(extraState = {}) {


    const base = mergeStore(makeBaseStore(), notificationsModule, foldersCrudModule, foldersTreeModule);
    return Object.assign(Object.create(null), base, {
        notifications: [],
        _notifId: 0,
        folders: [],
        isLoading: false,
        alertMessage: null,
        error: null,
        searchQuery: '',
        collapsedIds: {},
        folderDragMode: false,
        shouldUseDeferredTree: () => false,
        async refreshFolders() {},
        openFolder() {},
        mergeFolders() {},
        ...extraState,
    });
}

describe('foldersCrudModule — hasSiblingNamed', () => {
    it('detects duplicate at same parent', () => {
        const store = makeStore({
            folders: [{ id: 1, name: 'Photos', parentId: 0 }],
        });
        expect(store.hasSiblingNamed('Photos', 0)).toBe(true);
        expect(store.hasSiblingNamed('photos', 0)).toBe(true);
        expect(store.hasSiblingNamed('Videos', 0)).toBe(false);
    });

    it('ignores the excluded id', () => {
        const store = makeStore({
            folders: [{ id: 1, name: 'Photos', parentId: 0 }],
        });
        expect(store.hasSiblingNamed('Photos', 0, 1)).toBe(false);
    });

    it('does not cross parent boundaries', () => {
        const store = makeStore({
            folders: [{ id: 2, name: 'Photos', parentId: 5 }],
        });
        expect(store.hasSiblingNamed('Photos', 0)).toBe(false);
    });
});

describe('foldersCrudModule — createFolder', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('sets alertMessage and aborts on duplicate name', async () => {
        Api.createFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 1, name: 'Existing', parentId: 0 }],
        });
        await store.createFolder('Existing', 0);
        expect(Api.createFolder).not.toHaveBeenCalled();
        expect(store.alertMessage).toBeTruthy();
    });

    it('calls Api.createFolder and notifies on success', async () => {
        Api.createFolder.mockResolvedValue({ id: 5 });
        Api.getFolder.mockResolvedValue({ folder: { id: 5, name: 'NewFolder', parentId: 0 } });
        const store = makeStore({ mergeFolders: jest.fn() });
        await store.createFolder('NewFolder', 0);
        expect(Api.createFolder).toHaveBeenCalledWith('NewFolder', 0);
        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0].type).toBe('success');
    });

    it('preserves folder tree behavior', async () => {



        Api.createFolder.mockResolvedValue({ id: 312 });
        Api.getFolder.mockResolvedValue({
            folder: { id: 312, name: 'Фото Альбом', parentId: 7, count: 5, color: '#ff0000', hasChildren: true },
            taxonomy: 'plathix_folder',
        });
        const store = makeStore({
            refreshFolders: jest.fn().mockResolvedValue({}),
            mergeFolders: jest.fn(),
        });
        await store.createFolder('Фото  Альбом', 7);
        expect(Api.getFolder).toHaveBeenCalledWith(312);

        expect(store.mergeFolders).toHaveBeenCalledTimes(2);
        const [optimistic] = store.mergeFolders.mock.calls[0][0];
        expect(optimistic).toMatchObject({ id: 312, count: 0, color: '' });
        const [full] = store.mergeFolders.mock.calls[1][0];

        expect(full).toMatchObject({ id: 312, count: 5, color: '#ff0000', hasChildren: true });

        expect(store.refreshFolders).not.toHaveBeenCalled();
    });

    it('falls back to a silent refresh when getFolder fails — optimistic folder stays visible', async () => {



        Api.createFolder.mockResolvedValue({ id: 312 });
        Api.getFolder.mockRejectedValue(new Error('Folder no longer exists.'));
        const store = makeStore({
            refreshFolders: jest.fn().mockResolvedValue({}),
            mergeFolders: jest.fn(),
            openFolder: jest.fn(),
        });
        await store.createFolder('NewFolder', 0);

        expect(store.mergeFolders).toHaveBeenCalledTimes(1);
        expect(store.mergeFolders.mock.calls[0][0][0]).toMatchObject({ id: 312, name: 'NewFolder', count: 0 });
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(312);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(0);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('refreshes cached state when data changes', async () => {


        Api.createFolder.mockResolvedValue({ id: 312 });
        Api.getFolder.mockRejectedValue(new Error('Folder no longer exists.'));
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
            mergeFolders: jest.fn(),
        });

        await store.createFolder('NewFolder', 0);

        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('plathix:folder-created');
        expect(store.notifications[0].message).toContain('Folder created');
        dispatchSpy.mockRestore();
    });

    it('preserves folder tree behavior', async () => {


        Api.createFolder.mockResolvedValue({ id: 42 });
        Api.getFolder.mockResolvedValue({ folder: { id: 42, name: 'foo', parentId: 3, count: 7, color: 'blue', hasChildren: false } });
        const store = makeStore({ mergeFolders: jest.fn(), refreshFolders: jest.fn().mockResolvedValue({}) });
        await store.createFolder('foo', 3);

        expect(store.mergeFolders).toHaveBeenCalledTimes(2);
        const [skeleton] = store.mergeFolders.mock.calls[0][0];
        expect(skeleton).toMatchObject({ id: 42, name: 'foo', parentId: 3, count: 0, color: '', hasChildren: false, isProtected: false });
        const [fullDto] = store.mergeFolders.mock.calls[1][0];
        expect(fullDto).toMatchObject({ id: 42, name: 'foo', count: 7, color: 'blue' });
    });

    it('preserves folder tree behavior', async () => {


        Api.createFolder.mockResolvedValue({ id: 312 });
        Api.getFolder.mockResolvedValue({ folder: { id: 312, name: 'NewFolder', parentId: 0 } });
        const store = makeStore({
            mergeFolders: jest.fn(),
            openFolder: jest.fn(),
            openId: 157,
        });
        await store.createFolder('NewFolder', 0);
        expect(store.openFolder).not.toHaveBeenCalled();
        expect(store.openId).toBe(157);
    });

    it('does not insert a folder when the API returns no id', async () => {
        Api.createFolder.mockResolvedValue({});
        const store = makeStore({
            mergeFolders: jest.fn(),
            openFolder: jest.fn(),
        });
        await store.createFolder('NewFolder', 0);
        expect(Api.getFolder).not.toHaveBeenCalled();
        expect(store.mergeFolders).not.toHaveBeenCalled();
        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('sets error on API failure', async () => {
        Api.createFolder.mockRejectedValue(new Error('Server error'));
        const store = makeStore();
        await store.createFolder('BadFolder', 0);
        expect(store.error).toBe('Server error');
        expect(store.notifications).toHaveLength(0);
    });

    it('always resets isLoading', async () => {
        Api.createFolder.mockRejectedValue(new Error('fail'));
        const store = makeStore();
        await store.createFolder('X', 0);
        expect(store.isLoading).toBe(false);
    });
});

describe('foldersCrudModule — showNewFolderForm / hideNewFolderForm', () => {
    it('sets newFolderParentId', async() => {
        const store = makeStore();
        await store.showNewFolderForm(7);
        expect(store.newFolderParentId).toBe(7);
        store.hideNewFolderForm();
        expect(store.newFolderParentId).toBeNull();
    });


    it('preserves folder tree behavior', async() => {

        const store = makeStore({
            folders: [
                { id: 1, parentId: 0 },
                { id: 2, parentId: 1 },
                { id: 3, parentId: 2 },
            ],
            collapsedIds: { 1: true, 2: true, 3: true },
        });

        await store.showNewFolderForm(3);


        expect(store.collapsedIds).toEqual({});
        expect(store.newFolderParentId).toBe(3);
    });

    it('deferred: loads ancestor children so the parent node renders', async() => {
        const loadFolderChildren = jest.fn(() => Promise.resolve());
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0 },
                { id: 2, parentId: 1 },
                { id: 3, parentId: 2 },
            ],
            collapsedIds: {},
            shouldUseDeferredTree: () => true,
            hasLoadedChildren: () => false,
            loadFolderChildren,
        });

        await store.showNewFolderForm(3);


        expect(loadFolderChildren.mock.calls.map((c) => c[0])).toEqual([1, 2]);
    });

    it('root new folder (parentId=0) does not touch collapsedIds', async() => {
        const store = makeStore({ collapsedIds: { 5: true } });
        await store.showNewFolderForm(0);
        expect(store.collapsedIds).toEqual({ 5: true });
        expect(store.newFolderParentId).toBe(0);
    });
});





describe('preserves folder tree behavior', () => {
    let addSpy;
    let removeSpy;

    beforeEach(() => {
        jest.useFakeTimers();
        addSpy = jest.spyOn(document, 'addEventListener');
        removeSpy = jest.spyOn(document, 'removeEventListener');
    });

    afterEach(() => {
        jest.runOnlyPendingTimers();
        jest.useRealTimers();
        addSpy.mockRestore();
        removeSpy.mockRestore();
    });

    const clickListeners = (spy) =>
        spy.mock.calls.filter((c) => c[0] === 'click' && c[2] === true);

    it('coalesces repeated events into a single handled call', async() => {
        const store = makeStore();
        await store.showNewFolderForm(0);
        jest.runOnlyPendingTimers();
        expect(clickListeners(addSpy)).toHaveLength(1);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async() => {
        const store = makeStore();
        await store.showNewFolderForm(0);
        jest.runOnlyPendingTimers();
        await store.showNewFolderForm(3);
        jest.runOnlyPendingTimers();

        expect(clickListeners(addSpy).length).toBe(2);
        expect(clickListeners(removeSpy).length).toBeGreaterThanOrEqual(1);
    });

    it('coalesces repeated events into a single handled call', async() => {
        const store = makeStore();
        await store.showNewFolderForm(0);
        jest.runOnlyPendingTimers();
        const handler = store._newFolderOutsideClickHandler;
        expect(handler).toBeInstanceOf(Function);
        store.hideNewFolderForm();
        expect(removeSpy).toHaveBeenCalledWith('click', handler, true);
        expect(store._newFolderOutsideClickHandler).toBeNull();
    });

    it('coalesces repeated events into a single handled call', async() => {
        const store = makeStore();
        await store.showNewFolderForm(0);
        store.hideNewFolderForm();
        jest.runOnlyPendingTimers();
        expect(store._newFolderOutsideClickHandler).toBeNull();
        expect(clickListeners(addSpy)).toHaveLength(0);
    });
});

describe('preserves folder tree behavior', () => {
    let addSpy;
    let removeSpy;

    beforeEach(() => {
        jest.useFakeTimers();
        addSpy = jest.spyOn(document, 'addEventListener');
        removeSpy = jest.spyOn(document, 'removeEventListener');
    });

    afterEach(() => {
        jest.runOnlyPendingTimers();
        jest.useRealTimers();
        addSpy.mockRestore();
        removeSpy.mockRestore();
    });

    const clickListeners = (spy) =>
        spy.mock.calls.filter((c) => c[0] === 'click' && c[2] === true);

    it('coalesces repeated events into a single handled call', () => {
        const store = makeStore();
        store.showRenameForm({ id: 1, name: 'A' });
        jest.runOnlyPendingTimers();
        expect(clickListeners(addSpy)).toHaveLength(1);
    });

    it('keeps upload links scoped to the active folder', () => {
        const store = makeStore();
        store.showRenameForm({ id: 1, name: 'A' });
        jest.runOnlyPendingTimers();
        store.showRenameForm({ id: 2, name: 'B' });
        jest.runOnlyPendingTimers();
        expect(clickListeners(addSpy).length).toBe(2);
        expect(clickListeners(removeSpy).length).toBeGreaterThanOrEqual(1);
    });

    it('coalesces repeated events into a single handled call', () => {
        const store = makeStore();
        store.showRenameForm({ id: 1, name: 'A' });
        jest.runOnlyPendingTimers();
        const handler = store._renameOutsideClickHandler;
        expect(handler).toBeInstanceOf(Function);
        store.hideRenameForm();
        expect(removeSpy).toHaveBeenCalledWith('click', handler, true);
        expect(store._renameOutsideClickHandler).toBeNull();
    });

    it('coalesces repeated events into a single handled call', async() => {
        const store = makeStore({ renameFolder: jest.fn().mockResolvedValue(undefined) });
        store.showRenameForm({ id: 1, name: 'A' });
        jest.runOnlyPendingTimers();
        const handler = store._renameOutsideClickHandler;
        await store.submitRename();
        expect(removeSpy).toHaveBeenCalledWith('click', handler, true);
        expect(store._renameOutsideClickHandler).toBeNull();
    });

    it('coalesces repeated events into a single handled call', () => {
        const store = makeStore();
        store.showRenameForm({ id: 1, name: 'A' });
        store.hideRenameForm();
        jest.runOnlyPendingTimers();
        expect(store._renameOutsideClickHandler).toBeNull();
        expect(clickListeners(addSpy)).toHaveLength(0);
    });
});

describe('foldersCrudModule — renameFolder', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('blocks duplicate rename within the same parent', async() => {
        const store = makeStore({
            folders: [
                { id: 1, name: 'Photos', parentId: 0 },
                { id: 2, name: 'Archive', parentId: 0 },
            ],
        });

        await store.renameFolder(2, 'Photos');

        expect(Api.renameFolder).not.toHaveBeenCalled();
        expect(store.alertMessage).toBe('A folder with this name already exists here.');
    });

    it('renames, refreshes and notifies on success', async() => {
        Api.renameFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 2, name: 'Archive', parentId: 0 }],
            refreshFolders: jest.fn().mockResolvedValue({}),
        });

        await store.renameFolder(2, 'Photos');

        expect(Api.renameFolder).toHaveBeenCalledWith(2, 'Photos');
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(2);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
        expect(store.notifications[0].message).toContain('Folder renamed');
    });

    it('refreshes cached state when data changes', async () => {
        Api.renameFolder.mockResolvedValue({});
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [{ id: 2, name: 'Archive', parentId: 0 }],
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
        });

        await store.renameFolder(2, 'Photos');

        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('plathix:folder-moved');
        expect(store.notifications[0].message).toContain('Folder renamed');
        dispatchSpy.mockRestore();
    });
});

describe('foldersCrudModule — deleteFolder', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('refreshes after deleting the current folder and opens uncategorized instead of trash', async() => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 10,
            deletingFolder: { id: 10, name: 'Work' },
        });

        await store.deleteFolder(10);

        expect(Api.deleteFolder).toHaveBeenCalledWith(10, 'delete');
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(10);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(77);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
        expect(memClear).toHaveBeenCalled();
        expect(store.openFolder).toHaveBeenCalledWith(5);
        expect(store.deletingFolder).toBeNull();
        expect(store.notifications[0].message).toContain('Moved to Trash');
    });

    it('does not navigate away when deleting a different folder', async() => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work', parentId: 0, isProtected: false },
                { id: 11, name: 'Archive', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 10,
            deletingFolder: { id: 11, name: 'Archive' },
        });

        await store.deleteFolder(11);

        expect(Api.deleteFolder).toHaveBeenCalledWith(11, 'delete');
        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('navigates away when deleting an ancestor of the open folder', async() => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Projects', parentId: 0, isProtected: false },
                { id: 12, name: 'Client', parentId: 10, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 12,
            deletingFolder: { id: 10, name: 'Projects' },
        });

        await store.deleteFolder(10);

        expect(store.openFolder).toHaveBeenCalledWith(5);
    });

    it('does not navigate anywhere when fallback folder is unavailable', async() => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 10, name: 'Projects', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 10,
            deletingFolder: { id: 10, name: 'Projects' },
        });

        await store.deleteFolder(10);

        expect(store.openFolder).not.toHaveBeenCalled();
        expect(store.notifications[0].message).toContain('Moved to Trash');
    });

    it('rolls back optimistic removal and does not clear media cache when the API call fails', async() => {
        Api.deleteFolder.mockRejectedValue(new Error('network'));
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 10,
            deletingFolder: { id: 10, name: 'Work' },
        });

        await store.deleteFolder(10);

        expect(store.folders.map((f) => f.id).sort((a, b) => a - b)).toEqual([5, 10, 77]);
        expect(memClear).not.toHaveBeenCalled();
        expect(store.refreshFolders).not.toHaveBeenCalled();
        expect(store.openFolder).not.toHaveBeenCalled();
        expect(store.notifications[0].type).toBe('error');
        expect(store.notifications[0].message).toContain('could not be deleted');
    });

    it('handles trash workflow consistently', async() => {



        Api.deleteFolder.mockResolvedValue({});
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
            openFolder: jest.fn(),
            openId: 0,
            deletingFolder: { id: 10, name: 'Work' },
        });

        await store.deleteFolder(10);

        expect(store.folders.map((f) => f.id).sort((a, b) => a - b)).toEqual([5, 77]);
        expect(memClear).toHaveBeenCalled();
        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('plathix:folder-deleted');
        expect(store.notifications[0].type).toBe('success');
        expect(store.notifications[0].message).toContain('Moved to Trash');
        dispatchSpy.mockRestore();
    });

    it('does not touch bulk select-mode state when deleting a single folder', async() => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5, name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work', parentId: 0, isProtected: false },
            ],
            refreshFolders: jest.fn().mockResolvedValue({}),
            openFolder: jest.fn(),
            openId: 10,
            deletingFolder: { id: 10, name: 'Work' },
            folderSelectMode: true,
            selectedFolderIds: [42],
        });

        await store.deleteFolder(10);

        expect(store.folderSelectMode).toBe(true);
        expect(store.selectedFolderIds).toEqual([42]);
    });
});

