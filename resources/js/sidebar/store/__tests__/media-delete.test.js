import { mediaDeleteModule } from '../media-delete.js';
import { notificationsModule } from '../notifications.js';
import { makeBaseStore } from '../test-base.js';
import { mergeStore } from '../utils.js';

jest.mock('../../api.js', () => ({
    Api: {
        trashMedia: jest.fn(),
        restoreMedia: jest.fn(),
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(),
    getRuntime: () => ({
        folders: [],
        openId: 0,
        trashFolderId: 77,
        caps: {},
    }),
}));

import { Api } from '../../api.js';
import { cacheInvalidateFolder } from '../../static-list/cache.js';
import { getMediaFrame } from '../../runtime.js';

function makeStore(extraState = {}) {
    const base = mergeStore(makeBaseStore(), notificationsModule, mediaDeleteModule);
    return Object.assign(Object.create(null), base, {
        notifications: [],
        _notifId: 0,
        folders: [],
        openId: 12,
        selected: [1, 2],
        mediaTrashConfirm: null,
        mediaTrashPending: false,
        mediaRestorePending: false,
        error: null,
        refreshFolders: jest.fn().mockResolvedValue({}),
        notify: jest.fn(),
        refreshMediaFrame: jest.fn(),
        applyFolderFilter: jest.fn(),
        getSelectedItemIds: jest.fn(() => [1, 2]),
        ...extraState,
    });
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

describe('mediaDeleteModule', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('shows confirm with selected ids', () => {
        const store = makeStore();
        store.showMediaTrashConfirm();
        expect(store.mediaTrashConfirm).toEqual([1, 2]);
    });

    it('sets error if nothing is selected for trash', () => {
        const store = makeStore({ getSelectedItemIds: jest.fn(() => []) });
        store.showMediaTrashConfirm();
        expect(store.mediaTrashConfirm).toBeNull();
        expect(store.error).toBe('No items selected.');
    });

    it('refreshes static list after trash and invalidates cache', async () => {
        Api.trashMedia.mockResolvedValue({ trashed: [1, 2], failed: [] });
        getMediaFrame.mockReturnValue(null);
        document.body.innerHTML = `
            <table><tbody>
                <tr id="post-1"></tr>
                <tr id="post-2"></tr>
            </tbody></table>
        `;
        const store = makeStore({ mediaTrashConfirm: [1, 2] });

        await store.confirmMediaTrash();

        expect(Api.trashMedia).toHaveBeenCalledWith([1, 2]);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(12);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(77);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.applyFolderFilter).toHaveBeenCalledWith(12);
        expect(store.refreshMediaFrame).not.toHaveBeenCalled();
        expect(store.selected).toEqual([]);
        expect(document.querySelector('tr#post-1')).toBeNull();
        expect(document.querySelector('tr#post-2')).toBeNull();
        expect(store.notify).toHaveBeenCalledWith('success', '2 files moved to trash');
    });

    it('resets wp.media selection and refreshes media frame when trashing in media mode', async () => {
        Api.trashMedia.mockResolvedValue({ trashed: [5], failed: [] });
        const selection = { reset: jest.fn() };
        getMediaFrame.mockReturnValue({
            state: () => ({
                get: (key) => (key === 'selection' ? selection : null),
            }),
        });
        document.body.innerHTML = `<div class="attachment" data-id="5"></div>`;
        const store = makeStore({
            mediaTrashConfirm: [5],
            getSelectedItemIds: jest.fn(() => [5]),
            selected: [5],
        });

        await store.confirmMediaTrash();

        expect(selection.reset).toHaveBeenCalled();
        expect(store.refreshMediaFrame).toHaveBeenCalled();
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        expect(document.querySelector('.attachment[data-id="5"]')).toBeNull();
    });

    it('restores items and refreshes static list', async () => {
        Api.restoreMedia.mockResolvedValue({ restored: [1, 2], failed: [] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        await store.restoreMedia();

        expect(Api.restoreMedia).toHaveBeenCalledWith([1, 2], 0);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(12);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.applyFolderFilter).toHaveBeenCalledWith(12);
        expect(store.notify).toHaveBeenCalledWith('success', '2 files restored');
        expect(store.selected).toEqual([]);
    });

    it('restores items in media mode and resets selection', async () => {
        Api.restoreMedia.mockResolvedValue({ restored: [7], failed: [] });
        const selection = { reset: jest.fn() };
        getMediaFrame.mockReturnValue({
            state: () => ({
                get: (key) => (key === 'selection' ? selection : null),
            }),
        });
        const store = makeStore({
            getSelectedItemIds: jest.fn(() => [7]),
            selected: [7],
        });

        await store.restoreMedia(5);

        expect(Api.restoreMedia).toHaveBeenCalledWith([7], 5);
        expect(selection.reset).toHaveBeenCalled();
        expect(store.refreshMediaFrame).toHaveBeenCalled();
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(5);
    });

    it('invalidates both current trash folder and explicit restore target', async () => {
        Api.restoreMedia.mockResolvedValue({ restored: [7], failed: [] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({
            openId: 77,
            getSelectedItemIds: jest.fn(() => [7]),
            selected: [7],
        });

        await store.restoreMedia(5);

        expect(cacheInvalidateFolder).toHaveBeenNthCalledWith(1, 77);
        expect(cacheInvalidateFolder).toHaveBeenNthCalledWith(2, 5);
        expect(store.applyFolderFilter).toHaveBeenCalledWith(77);
    });

    it('keeps failed ids selected after trash partial failure', async () => {
        Api.trashMedia.mockResolvedValue({ trashed: [1], failed: ['2'] });
        getMediaFrame.mockReturnValue(null);
        document.body.innerHTML = `
            <table><tbody>
                <tr id="post-1"></tr>
                <tr id="post-2"></tr>
            </tbody></table>
        `;
        const store = makeStore({ mediaTrashConfirm: [1, 2] });

        await store.confirmMediaTrash();

        expect(store.selected).toEqual([2]);
        expect(document.querySelector('tr#post-1')).toBeNull();
        expect(document.querySelector('tr#post-2')).not.toBeNull();
        expect(store.error).toBe('1 files could not be moved to trash');
    });

    it('keeps failed ids selected after restore partial failure', async () => {
        Api.restoreMedia.mockResolvedValue({ restored: [1], failed: ['2'] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        await store.restoreMedia();

        expect(store.selected).toEqual([2]);
        expect(store.error).toBe('1 files could not be restored');
    });

    it('ignores stale trash responses from an older request', async () => {
        const first = deferred();
        const second = deferred();
        Api.trashMedia
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ mediaTrashConfirm: [1] });

        const firstRun = store.confirmMediaTrash();
        store.mediaTrashConfirm = [2];
        const secondRun = store.confirmMediaTrash();

        second.resolve({ trashed: [2], failed: [] });
        await secondRun;

        first.resolve({ trashed: [1], failed: [] });
        await firstRun;

        expect(store.notify).toHaveBeenCalledTimes(1);
        expect(store.notify).toHaveBeenCalledWith('success', '1 file moved to trash');
        expect(Api.trashMedia).toHaveBeenNthCalledWith(1, [1]);
        expect(Api.trashMedia).toHaveBeenNthCalledWith(2, [2]);
        expect(store.mediaTrashPending).toBe(false);
    });

    it('ignores stale restore failures from an older request', async () => {
        const first = deferred();
        const second = deferred();
        Api.restoreMedia
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({
            getSelectedItemIds: jest.fn()
                .mockReturnValueOnce([1])
                .mockReturnValueOnce([2]),
        });

        const firstRun = store.restoreMedia();
        const secondRun = store.restoreMedia();

        second.resolve({ restored: [2], failed: [] });
        await secondRun;

        first.reject(new Error('stale failed'));
        await firstRun;

        expect(store.error).toBeNull();
        expect(store.notify).toHaveBeenCalledTimes(1);
        expect(store.notify).toHaveBeenCalledWith('success', '1 file restored');
        expect(store.mediaRestorePending).toBe(false);
    });

    it('clears error on AbortError during restore', async () => {
        Api.restoreMedia.mockRejectedValue(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ error: 'old error' });

        await store.restoreMedia();

        expect(store.error).toBeNull();
        expect(store.mediaRestorePending).toBe(false);
    });

    it('clears error on AbortError during trash confirm', async () => {
        Api.trashMedia.mockRejectedValue(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ mediaTrashConfirm: [1, 2], error: 'old error' });

        await store.confirmMediaTrash();

        expect(store.error).toBeNull();
        expect(store.mediaTrashPending).toBe(false);
    });

    // [internal]: сервер мог реально выполнить trash/restore, но вернуть нечитаемый
    // ответ — reconcile через silent refresh вместо тихого "0 сделано".
    it('reconciles via silent refreshFolders on rest_write_indeterminate during trash confirm', async () => {
        Api.trashMedia.mockRejectedValue(Object.assign(new Error('indeterminate'), { code: 'rest_write_indeterminate' }));
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ mediaTrashConfirm: [1, 2] });

        await store.confirmMediaTrash();

        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.error).toBe('The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
    });

    it('reconciles via silent refreshFolders on rest_write_indeterminate during restore', async () => {
        Api.restoreMedia.mockRejectedValue(Object.assign(new Error('indeterminate'), { code: 'rest_write_indeterminate' }));
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        await store.restoreMedia();

        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.error).toBe('The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
    });

    it('sets error if nothing is selected for restore', async () => {
        const store = makeStore({ getSelectedItemIds: jest.fn(() => []) });

        await store.restoreMedia();

        expect(Api.restoreMedia).not.toHaveBeenCalled();
        expect(store.error).toBe('No items selected.');
        expect(store.mediaRestorePending).toBe(false);
    });

    // [internal] (корень B): selectedMediaCount не пересчитывался сам после
    // optimistic removal/reset → кнопка-призрак. confirmMediaTrash/restoreMedia обязаны
    // сбросить его явно (= failed.length), симметрично this.selected.
    it('resets selectedMediaCount to 0 after full trash success (kills ghost button)', async () => {
        Api.trashMedia.mockResolvedValue({ trashed: [1, 2], failed: [] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ mediaTrashConfirm: [1, 2], selectedMediaCount: 2 });

        await store.confirmMediaTrash();

        expect(store.selectedMediaCount).toBe(0);
    });

    it('sets selectedMediaCount to failed count after partial trash failure', async () => {
        Api.trashMedia.mockResolvedValue({ trashed: [1], failed: ['2'] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ mediaTrashConfirm: [1, 2], selectedMediaCount: 2 });

        await store.confirmMediaTrash();

        expect(store.selectedMediaCount).toBe(1);
    });

    it('resets selectedMediaCount to 0 after full restore success', async () => {
        Api.restoreMedia.mockResolvedValue({ restored: [1, 2], failed: [] });
        getMediaFrame.mockReturnValue(null);
        const store = makeStore({ selectedMediaCount: 2 });

        await store.restoreMedia();

        expect(store.selectedMediaCount).toBe(0);
    });
});
