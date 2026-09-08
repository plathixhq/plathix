import { itemsModule } from '../items.js';
import { selectionModule } from '../selection.js';
import { treeStateModule } from '../tree-state.js';
import { mergeStore } from '../utils.js';

jest.mock('../../api.js', () => ({
    Api: {
        moveItemsBulk: jest.fn(),
        unassignItems: jest.fn(),
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(() => null),
    getScreenKind: jest.fn(() => 'static'),
    shouldUseStaticListFiltering: jest.fn(() => false),
    getRuntime: jest.fn(() => ({})),
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

jest.mock('../../media-grid-cache.js', () => ({
    memInvalidateFolder: jest.fn(),
}));

import { Api } from '../../api.js';
import { getMediaFrame, getScreenKind, shouldUseStaticListFiltering } from '../../runtime.js';
import { cacheInvalidateFolder } from '../../static-list/cache.js';
import { memInvalidateFolder } from '../../media-grid-cache.js';

function makeStore(extraState = {}) {
    const base = mergeStore(treeStateModule, itemsModule, selectionModule);
    return Object.assign(Object.create(null), base, {
        folders: [
            { id: 3, name: 'Current', count: 4 },
            { id: 7, name: 'Target', count: 1 },
        ],
        openId: 3,
        selected: [1, 2],
        error: null,
        notify: jest.fn(),
        refreshFolders: jest.fn().mockResolvedValue({}),
        applyFolderFilter: jest.fn(),
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

describe('itemsModule', () => {
    beforeEach(() => {
        jest.clearAllMocks();



        getMediaFrame.mockReturnValue(null);
        getScreenKind.mockReturnValue('static');
        shouldUseStaticListFiltering.mockReturnValue(false);
        document.body.innerHTML = '';
    });

    it('sets an error and skips API call when target folder is invalid', async() => {
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 0);

        expect(Api.moveItemsBulk).not.toHaveBeenCalled();
        expect(store.error).toBe('Open a destination folder first.');
        expect(store.selected).toEqual([1, 2]);
    });

    it('uses assigned fallback for optimistic count reconciliation and invalidates target/current caches', async() => {
        Api.moveItemsBulk.mockResolvedValue({ assigned: 2, failed: [] });
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 7);

        expect(store.folders.find((folder) => Number(folder.id) === 7)?.count).toBe(3);
        expect(cacheInvalidateFolder).toHaveBeenNthCalledWith(1, 7);
        expect(cacheInvalidateFolder).toHaveBeenNthCalledWith(2, 3);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        expect(store.notify).toHaveBeenCalledWith('success', '2 files moved → Target');
        expect(store.selected).toEqual([]);
    });

    it('refreshes cached state when data changes', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, failed: [], counts: { 7: 5, 3: 1 } });
        const store = makeStore({
            folders: [
                { id: 3, name: 'Current', color: '#abc', parentId: 0, count: 4 },
                { id: 7, name: 'Target', color: '#def', parentId: 0, count: 1 },
            ],
        });

        await store.moveItemsBulk([1, 2], 7);

        expect(store.refreshFolders).not.toHaveBeenCalled();
        const target = store.folders.find((f) => Number(f.id) === 7);
        const current = store.folders.find((f) => Number(f.id) === 3);
        expect(target.count).toBe(5);
        expect(current.count).toBe(1);

        expect(target.name).toBe('Target');
        expect(target.color).toBe('#def');
        expect(target.parentId).toBe(0);
        expect(current.name).toBe('Current');
        expect(current.color).toBe('#abc');
    });

    it('refreshes cached state when data changes', async() => {
        // The version-counter integration itself (patchFolder -> foldersVersion) is covered
        // directly against a real, non-flattened store in tree-state.test.js — this test only
        // proves moveItemsBulk still routes through patchFolder's in-place splice, not a
        // reference-replacing assignment, so search.js's cache-invalidation path stays reachable.
        Api.moveItemsBulk.mockResolvedValue({ assigned: 2, failed: [] });
        const store = makeStore();
        const originalFolders = store.folders;

        await store.moveItemsBulk([1, 2], 7);

        expect(store.folders).toBe(originalFolders);
    });

    it('preserves folder tree behavior', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 3, failed: [], counts: { 7: 6, 3: 0, 9: 2 } });
        const store = makeStore({
            folders: [
                { id: 3, name: 'Current', count: 4 },
                { id: 7, name: 'Target', count: 1 },
                { id: 9, name: 'ThirdSource', count: 5 },
            ],
        });

        await store.moveItemsBulk([1, 2, 5], 7);

        expect(store.refreshFolders).not.toHaveBeenCalled();
        expect(store.folders.find((f) => Number(f.id) === 7)?.count).toBe(6);
        expect(store.folders.find((f) => Number(f.id) === 3)?.count).toBe(0);
        expect(store.folders.find((f) => Number(f.id) === 9)?.count).toBe(2);
    });

    it('handles trash workflow consistently', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, restored: [1], failed: [], counts: {} });
        const store = makeStore();

        await store.moveItemsBulk([1], 7);

        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
    });

    it('handles trash workflow consistently', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, restored: [1], failed: [] });
        const store = makeStore();

        await store.moveItemsBulk([1], 7);

        expect(store.notify).toHaveBeenCalledWith('success', '1 file restored and moved → Target');
    });

    it('handles trash workflow consistently', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, restored: [1, 2], failed: [] });
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 7);

        expect(store.notify).toHaveBeenCalledWith('success', '2 files restored and moved → Target');
    });

    it('handles trash workflow consistently', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, restored: [], failed: [] });
        const store = makeStore();

        await store.moveItemsBulk([1], 7);

        expect(store.notify).toHaveBeenCalledWith('success', '1 file moved → Target');
    });

    it('rolls back optimistic count and restores selection on API failure', async() => {
        Api.moveItemsBulk.mockRejectedValue(new Error('move failed'));
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 7);

        expect(store.folders.find((folder) => Number(folder.id) === 7)?.count).toBe(1);
        expect(store.selected).toEqual([1, 2]);
        expect(store.error).toBe('move failed');
    });

    it('rolls back optimistic count and clears error on AbortError', async() => {
        Api.moveItemsBulk.mockRejectedValue(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        const store = makeStore({ error: 'old error' });

        await store.moveItemsBulk([1, 2], 7);

        expect(store.folders.find((folder) => Number(folder.id) === 7)?.count).toBe(1);
        expect(store.selected).toEqual([1, 2]);
        expect(store.error).toBeNull();
    });




    it('reconciles via silent refreshFolders on rest_write_indeterminate instead of trusting the optimistic rollback alone', async() => {
        Api.moveItemsBulk.mockRejectedValue(Object.assign(new Error('indeterminate'), { code: 'rest_write_indeterminate' }));
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 7);

        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.error).toBe('The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
    });

    it('removes only successful DOM items and keeps failed ids selected', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: ['2'], skipped: 1 });
        const selection = { reset: jest.fn() };
        getMediaFrame.mockReturnValue({
            trigger: jest.fn(),
            state: () => ({
                get: (key) => (key === 'selection' ? selection : null),
            }),
        });
        document.body.innerHTML = `
            <table><tbody>
                <tr id="post-1"></tr>
                <tr id="post-2"></tr>
            </tbody></table>
            <input id="cb-select-all-1" type="checkbox" checked>
            <input id="cb-select-all-2" type="checkbox" checked>
        `;
        const store = makeStore();

        await store.moveItemsBulk([1, 2], 7);

        expect(document.querySelector('tr#post-1')).toBeNull();
        expect(document.querySelector('tr#post-2')).not.toBeNull();
        expect(selection.reset).toHaveBeenCalled();
        expect(document.querySelector('#cb-select-all-1').checked).toBe(false);
        expect(document.querySelector('#cb-select-all-2').checked).toBe(false);
        expect(store.selected).toEqual([2]);
        expect(store.error).toBe('1 unchanged, 1 failed');
    });

    it('refreshes the visible folder instead of DOM-removing when moving within the current folder', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: [] });
        document.body.innerHTML = `
            <table><tbody><tr id="post-1"></tr></tbody></table>
            <input name="post[]" value="1" checked>
        `;
        const store = makeStore({ openId: 7 });

        await store.moveItemsBulk([1], 7);

        expect(store.applyFolderFilter).toHaveBeenCalledWith(7);
        expect(document.querySelector('tr#post-1')).not.toBeNull();
        expect(document.querySelector('input[name="post[]"]').checked).toBe(false);
    });

    it('ignores stale responses from an older move request', async() => {
        const first = deferred();
        const second = deferred();
        Api.moveItemsBulk
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore({
            notify: jest.fn(),
            refreshFolders: jest.fn().mockResolvedValue({}),
        });

        const firstRun = store.moveItemsBulk([1], 7);
        const secondRun = store.moveItemsBulk([2], 7);

        second.resolve({ moved: 1, failed: [] });
        await secondRun;

        first.resolve({ moved: 1, failed: [] });
        await firstRun;

        expect(store.notify).toHaveBeenCalledTimes(1);
        expect(store.notify).toHaveBeenCalledWith('success', '1 file moved → Target');
        expect(store.refreshFolders).toHaveBeenCalledTimes(1);
    });

    it('ignores stale failures from an older move request', async() => {
        const first = deferred();
        const second = deferred();
        Api.moveItemsBulk
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore({
            notify: jest.fn(),
            refreshFolders: jest.fn().mockResolvedValue({}),
        });

        const firstRun = store.moveItemsBulk([1], 7);
        const secondRun = store.moveItemsBulk([2], 7);

        second.resolve({ moved: 1, failed: [] });
        await secondRun;

        first.reject(new Error('stale failed'));
        await firstRun;

        expect(store.error).toBeNull();
        expect(store.notify).toHaveBeenCalledTimes(1);
        expect(store.notify).toHaveBeenCalledWith('success', '1 file moved → Target');
        expect(store.selected).toEqual([]);
    });


    it('resets pagination via applyFolderFilter when page becomes empty in static-list mode', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, failed: [] });
        shouldUseStaticListFiltering.mockReturnValue(true);

        document.body.innerHTML = '<table id="the-list"><tbody></tbody></table>';
        const store = makeStore({ openId: 3 });

        await store.moveItemsBulk([1, 2], 7);

        expect(store.applyFolderFilter).toHaveBeenCalledWith(3, { resetPage: true });
    });

    it('does not reset pagination when rows remain after bulk-move in static-list mode', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: [] });
        shouldUseStaticListFiltering.mockReturnValue(true);

        document.body.innerHTML = `
            <table id="the-list"><tbody>
                <tr id="post-99"></tr>
            </tbody></table>
        `;
        const store = makeStore({ openId: 3, selected: [1] });

        await store.moveItemsBulk([1], 7);

        expect(store.applyFolderFilter).not.toHaveBeenCalledWith(3, { resetPage: true });
    });



    function makeGridFrame() {
        const selection = { reset: jest.fn() };
        return {
            trigger: jest.fn(),
            state: () => ({
                get: (key) => (key === 'selection' ? selection : null),
            }),
        };
    }




    //


    it('keeps upload links scoped to the active folder', async() => {

        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: [], counts_recomputed: [7, 9] });
        const store = makeStore({ openId: 3 });

        await store.moveItemsBulk([1], 7);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(9);
        expect(memInvalidateFolder).toHaveBeenCalledWith(9);
    });




    it('covers public behavior without internal references', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: [], counts_recomputed: [] });
        const store = makeStore({ openId: 3 });

        await store.moveItemsBulk([1], 7);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(7);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(3);
    });



    it('covers public behavior without internal references', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: [] });
        const store = makeStore({ openId: 3 });

        await store.moveItemsBulk([1], 7);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(7);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(3);
    });

    it('triggers selection:action:done to exit grid select-mode on full success', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, failed: [] });
        const frame = makeGridFrame();
        getMediaFrame.mockReturnValue(frame);
        getScreenKind.mockReturnValue('static');
        const store = makeStore({ openId: 7 });

        await store.moveItemsBulk([1, 2], 7);

        expect(frame.trigger).toHaveBeenCalledWith('selection:action:done');
    });

    it('covers public behavior without internal references', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 1, failed: ['2'] });
        const frame = makeGridFrame();
        getMediaFrame.mockReturnValue(frame);
        getScreenKind.mockReturnValue('static');
        const store = makeStore({ openId: 7 });

        await store.moveItemsBulk([1, 2], 7);

        expect(frame.trigger).not.toHaveBeenCalledWith('selection:action:done');
    });

    it('does NOT exit select-mode in modal picker context (post/Elementor)', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, failed: [] });
        const frame = makeGridFrame();
        getMediaFrame.mockReturnValue(frame);
        getScreenKind.mockReturnValue('modal');
        const store = makeStore({ openId: 7 });

        await store.moveItemsBulk([1, 2], 7);

        expect(frame.trigger).not.toHaveBeenCalledWith('selection:action:done');
    });

    it('does not throw when no media frame (list-view / static-list, getMediaFrame() === null)', async() => {
        Api.moveItemsBulk.mockResolvedValue({ moved: 2, failed: [] });
        getMediaFrame.mockReturnValue(null);
        getScreenKind.mockReturnValue('static');
        const store = makeStore({ openId: 7 });

        await expect(store.moveItemsBulk([1, 2], 7)).resolves.toBeUndefined();
        expect(store.notify).toHaveBeenCalledWith('success', '2 files moved → Target');
    });

    describe('preserves folder tree behavior', () => {







        it('applies the success-diff count correction to the right folder after folders was reordered during await', async() => {
            const move = deferred();
            Api.moveItemsBulk.mockImplementationOnce(() => move.promise);
            const store = makeStore();

            const run = store.moveItemsBulk([1, 2], 7);

            store.folders = [
                { id: 7, name: 'Target', count: 10 },
                { id: 3, name: 'Current', count: 20 },
            ];

            move.resolve({ moved: 1, failed: [] });
            await run;

            const target = store.folders.find((f) => Number(f.id) === 7);
            const current = store.folders.find((f) => Number(f.id) === 3);
            expect(target.count).toBe(9);
            expect(current.count).toBe(20);
        });

        it('rolls back the correct folder count on failure after folders array shrank during await', async() => {
            const move = deferred();
            Api.moveItemsBulk.mockImplementationOnce(() => move.promise);
            const store = makeStore();

            const run = store.moveItemsBulk([1, 2], 7);

            store.folders = [{ id: 3, name: 'Current', count: 4 }];

            move.reject(new Error('network error'));
            await run.catch(() => {});

            expect(store.folders).toHaveLength(1);
            expect(store.folders[0]).toEqual({ id: 3, name: 'Current', count: 4 });
        });

        it('applies per-folder server counts by id, not by stale index, after folders was reordered during await', async() => {
            const move = deferred();
            Api.moveItemsBulk.mockImplementationOnce(() => move.promise);
            const store = makeStore();

            const run = store.moveItemsBulk([1, 2], 7);

            store.folders = [
                { id: 9, name: 'ThirdFolder', count: 0 },
                { id: 7, name: 'Target', count: 3 },
                { id: 3, name: 'Current', count: 4 },
            ];

            move.resolve({ moved: 2, failed: [], counts: { 7: 5, 9: 1 } });
            await run;

            expect(store.folders.find((f) => Number(f.id) === 7)?.count).toBe(5);
            expect(store.folders.find((f) => Number(f.id) === 9)?.count).toBe(1);
            expect(store.folders.find((f) => Number(f.id) === 3)?.count).toBe(4);
        });
    });
});

