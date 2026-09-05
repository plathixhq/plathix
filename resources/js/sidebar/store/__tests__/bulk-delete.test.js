import { bulkDeleteModule } from '../bulk-delete.js';
import { notificationsModule } from '../notifications.js';
import { makeBaseStore } from '../test-base.js';
import { mergeStore } from '../utils.js';

jest.mock('../../api.js', () => ({
    Api: {
        deleteFolder: jest.fn(),
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(() => ({ trashFolderId: 77 })),
}));

jest.mock('../../media-grid-cache.js', () => ({
    memClear: jest.fn(),
}));

import { Api } from '../../api.js';
import { memClear } from '../../media-grid-cache.js';

function makeStore(extraState = {}) {
    const base = mergeStore(makeBaseStore(), notificationsModule, bulkDeleteModule);
    return Object.assign(Object.create(null), base, {
        notifications: [],
        _notifId: 0,
        folders: [],
        isLoading: false,
        alertMessage: null,
        error: null,
        folderSelectMode: false,
        selectedFolderIds: [],
        _selectedFolderIdsSet: new Set(),
        _hasChildrenSet: new Set(),
        deletingFoldersBulk: null,
        bulkDeleteHasNested: false,
        openId: 0,
        async refreshFolders() {},
        openFolder: jest.fn(),
        ...extraState,
    });
}

describe('bulkDeleteModule — confirmDeleteSelectedFolders shouldLeaveCurrentView', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('navigates to uncategorized when the open folder is in the deleted set', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash',        parentId: 0, isProtected: true },
                { id: 5,  name: 'Uncategorized', parentId: 0, isProtected: true },
                { id: 10, name: 'Work',          parentId: 0, isProtected: false },
            ],
            openId: 10,
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.openFolder).toHaveBeenCalledWith(5);
    });

    it('navigates away when the open folder is a descendant of a deleted folder', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash',        parentId: 0,  isProtected: true },
                { id: 5,  name: 'Uncategorized', parentId: 0,  isProtected: true },
                { id: 10, name: 'Projects',      parentId: 0,  isProtected: false },
                { id: 12, name: 'Client',        parentId: 10, isProtected: false },
            ],
            openId: 12,
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.openFolder).toHaveBeenCalledWith(5);
    });

    it('does not navigate when deleting a folder unrelated to the current view', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash',   parentId: 0, isProtected: true },
                { id: 5,  name: 'Uncat',   parentId: 0, isProtected: true },
                { id: 10, name: 'Work',    parentId: 0, isProtected: false },
                { id: 11, name: 'Archive', parentId: 0, isProtected: false },
            ],
            openId: 10,
            deletingFoldersBulk: [{ id: 11 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('does not navigate when no folder is currently open', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 5,  name: 'Uncat', parentId: 0, isProtected: true },
                { id: 10, name: 'Work',  parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('does not call openFolder when no non-trash protected folder remains after deletion', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 10, name: 'Work',  parentId: 0, isProtected: false },
            ],
            openId: 10,
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.openFolder).not.toHaveBeenCalled();
    });

    it('resets folderSelectMode, selectedFolderIds, and _selectedFolderIdsSet regardless of outcome', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
            ],
            openId: 0,
            folderSelectMode: true,
            selectedFolderIds: [10],
            _selectedFolderIdsSet: new Set([10]),
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.folderSelectMode).toBe(false);
        expect(store.selectedFolderIds).toEqual([]);
        expect(store._selectedFolderIdsSet.has(10)).toBe(false);
    });

    it('emits a success notification with the count of deleted folders', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 77, parentId: 0, isProtected: true }],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0].type).toBe('success');
        expect(store.notifications[0].message).toContain('2');
    });

    it('clears the client media-grid cache after deleting folders ([internal])', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 77, parentId: 0, isProtected: true }],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }],
        });

        await store.confirmDeleteSelectedFolders();

        expect(memClear).toHaveBeenCalled();
    });

    // [internal]: массовое удаление ДОЛЖНО диспатчить FOLDER_DELETED — панель корзины
    // (folder-trash-panel.js) перерисовывается только по этому событию. Без него bulk помечал
    // папки trashed на сервере, но корзина и счётчик не обновлялись до ручного одиночного удаления.
    it('dispatches a FOLDER_DELETED window event so the trash panel refreshes', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [{ id: 77, parentId: 0, isProtected: true }],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
        });

        await store.confirmDeleteSelectedFolders();

        const events = dispatchSpy.mock.calls.map(([e]) => e?.type);
        expect(events).toContain('plathix:folder-deleted');
        dispatchSpy.mockRestore();
    });
});

describe('bulkDeleteModule — showBulkDeleteConfirm', () => {
    it('populates deletingFoldersBulk from _selectedFolderIdsSet', () => {
        const store = makeStore({
            folders: [
                { id: 10, name: 'Work',    parentId: 0 },
                { id: 11, name: 'Archive', parentId: 0 },
                { id: 12, name: 'Other',   parentId: 0 },
            ],
            _selectedFolderIdsSet: new Set([10, 12]),
        });

        store.showBulkDeleteConfirm();

        expect(store.deletingFoldersBulk).toHaveLength(2);
        expect(store.deletingFoldersBulk.map(f => Number(f.id))).toEqual(
            expect.arrayContaining([10, 12])
        );
    });

    it('sets bulkDeleteHasNested when any selected folder has children', () => {
        const store = makeStore({
            folders: [
                { id: 10, name: 'Work',   parentId: 0 },
                { id: 11, name: 'Events', parentId: 0 },
            ],
            _selectedFolderIdsSet: new Set([10, 11]),
            _hasChildrenSet: new Set([10]),
        });

        store.showBulkDeleteConfirm();

        expect(store.bulkDeleteHasNested).toBe(true);
    });

    it('leaves bulkDeleteHasNested false when no selected folder has children', () => {
        const store = makeStore({
            folders: [{ id: 10, name: 'Work', parentId: 0 }],
            _selectedFolderIdsSet: new Set([10]),
            _hasChildrenSet: new Set(),
        });

        store.showBulkDeleteConfirm();

        expect(store.bulkDeleteHasNested).toBe(false);
    });

    it('does nothing when selection is empty', () => {
        const store = makeStore({
            folders: [{ id: 10, name: 'Work', parentId: 0 }],
            _selectedFolderIdsSet: new Set(),
        });

        store.showBulkDeleteConfirm();

        expect(store.deletingFoldersBulk).toBeNull();
    });
});

describe('bulkDeleteModule — partial failure resilience ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('keeps only failed folders in the tree when some requests reject', async () => {
        Api.deleteFolder
            .mockResolvedValueOnce({})   // id 10 — success
            .mockRejectedValueOnce(new Error('Network error'));  // id 11 — fail
        const refreshFolders = jest.fn().mockResolvedValue(undefined);
        const store = makeStore({
            folders: [
                { id: 77, name: 'Trash', parentId: 0, isProtected: true },
                { id: 10, name: 'Work',  parentId: 0, isProtected: false },
                { id: 11, name: 'Other', parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
            refreshFolders,
        });

        await store.confirmDeleteSelectedFolders();

        const folderIds = store.folders.map(f => Number(f.id));
        expect(folderIds).not.toContain(10);  // успешно удалён → не в дереве
        expect(folderIds).toContain(11);      // сбой → откат в дерево
    });

    it('calls refreshFolders and dispatches FOLDER_DELETED even on partial failure', async () => {
        Api.deleteFolder
            .mockResolvedValueOnce({})
            .mockRejectedValueOnce(new Error('Server error'));
        const refreshFolders = jest.fn().mockResolvedValue(undefined);
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
                { id: 11, parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
            refreshFolders,
        });

        await store.confirmDeleteSelectedFolders();

        // [internal]: аргументы, а не только факт вызова. Пересборка loadedParentIds
        // происходит лишь при полном запросе (navigation.js), поэтому форма вызова здесь
        // несёт инвариант: подмена на усекающий params оставила бы Set с id уже удалённых
        // папок. Одиночный путь уже защищён так же (folders-crud.test.js).
        expect(refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('plathix:folder-deleted');
        dispatchSpy.mockRestore();
    });

    it('calls memClear even on partial failure', async () => {
        Api.deleteFolder
            .mockResolvedValueOnce({})
            .mockRejectedValueOnce(new Error('fail'));
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
                { id: 11, parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
            refreshFolders: jest.fn().mockResolvedValue(undefined),
        });

        await store.confirmDeleteSelectedFolders();

        expect(memClear).toHaveBeenCalled();
    });

    it('emits success notification for succeeded ids and error notification for failed ids', async () => {
        Api.deleteFolder
            .mockResolvedValueOnce({})
            .mockRejectedValueOnce(new Error('fail'));
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
                { id: 11, parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
            refreshFolders: jest.fn().mockResolvedValue(undefined),
        });

        await store.confirmDeleteSelectedFolders();

        const types = store.notifications.map(n => n.type);
        expect(types).toContain('success');
        expect(types).toContain('error');
    });

    it('rolls back all folders and emits only error notification on total failure', async () => {
        Api.deleteFolder
            .mockRejectedValueOnce(new Error('fail A'))
            .mockRejectedValueOnce(new Error('fail B'));
        const refreshFolders = jest.fn().mockResolvedValue(undefined);
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
                { id: 11, parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }, { id: 11 }],
            refreshFolders,
        });

        await store.confirmDeleteSelectedFolders();

        const folderIds = store.folders.map(f => Number(f.id));
        expect(folderIds).toContain(10);
        expect(folderIds).toContain(11);
        expect(store.notifications.map(n => n.type)).toEqual(['error']);
        expect(store.notifications.map(n => n.type)).not.toContain('success');
    });
});

describe('bulkDeleteModule — refreshFolders failure does not swallow independent side-effects ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('still clears media-grid cache, dispatches FOLDER_DELETED, and notifies when refreshFolders rejects', async () => {
        Api.deleteFolder.mockResolvedValue({});
        const dispatchSpy = jest.spyOn(window, 'dispatchEvent');
        const store = makeStore({
            folders: [
                { id: 77, parentId: 0, isProtected: true },
                { id: 10, parentId: 0, isProtected: false },
            ],
            openId: 0,
            deletingFoldersBulk: [{ id: 10 }],
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
        });

        await store.confirmDeleteSelectedFolders();

        expect(memClear).toHaveBeenCalled();
        expect(dispatchSpy.mock.calls.map(([e]) => e?.type)).toContain('plathix:folder-deleted');
        expect(store.notifications.map(n => n.type)).toContain('success');
        dispatchSpy.mockRestore();
    });
});
