import { navigationModule } from '../navigation.js';
import { selectionModule } from '../selection.js';
import { foldersTreeModule } from '../folders-tree.js';
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
    getRuntime: jest.fn(() => ({ trashFolderId: 999 })),
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
import { cacheClear } from '../../static-list/cache.js';
import { getMediaFrame } from '../../runtime.js';

function makeStore(extraState = {})
{
    // [internal]: домёрживаем РЕАЛЬНЫЙ foldersTreeModule (не стаб), чтобы openFolder
    // дёргал настоящий expandAncestors — иначе тест маскировал бы поведение (находка QA-скептика).
    const base = mergeStore(treeStateModule, navigationModule, selectionModule, foldersTreeModule);
    return Object.assign(Object.create(null), base, {
        folders: [],
        openId: 0,
        isLoading: false,
        error: null,
        searchQuery: '',
        collapsedIds: {},
        applyFolderFilter: jest.fn(),
        notify: jest.fn(),
        shouldUseDeferredTree: () => false,
        ...extraState,
    });
}

function deferred()
{
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

describe('navigationModule — refreshFolders', () => {
    beforeEach(() => jest.clearAllMocks());

    it('updates this.folders from API response', async() => {
        const mockFolders = [{ id: 1, name: 'A', parentId: 0 }];
        Api.getFolders.mockResolvedValue({ folders: mockFolders });
        const store = makeStore();
        await store.refreshFolders({ silent: true });
        expect(store.folders).toEqual(mockFolders);
    });

    it('sets isLoading true/false when not silent', async() => {
        Api.getFolders.mockResolvedValue({ folders: [] });
        const store = makeStore();
        const loading = [];
        Api.getFolders.mockImplementation(() => {
            loading.push(store.isLoading);
            return Promise.resolve({ folders: [] });
        });
        await store.refreshFolders({ silent: false });
        expect(loading[0]).toBe(true);
        expect(store.isLoading).toBe(false);
    });

    it('does not touch isLoading when silent', async() => {
        Api.getFolders.mockResolvedValue({ folders: [] });
        const store = makeStore();
        await store.refreshFolders({ silent: true });
        expect(store.isLoading).toBe(false);
    });

    it('sets error on non-abort failure', async() => {
        Api.getFolders.mockRejectedValue(new Error('timeout'));
        const store = makeStore();
        await expect(store.refreshFolders({ silent: true })).rejects.toThrow('timeout');
        expect(store.error).toBe('timeout');
    });

    it('does not set error on AbortError', async() => {
        const abortErr = new Error('aborted');
        abortErr.name = 'AbortError';
        Api.getFolders.mockRejectedValue(abortErr);
        const store = makeStore();
        await expect(store.refreshFolders({ silent: true })).rejects.toThrow();
        expect(store.error).toBeNull();
    });

    it('normalises non-array API response to empty array', async() => {
        Api.getFolders.mockResolvedValue({ folders: null });
        const store = makeStore();
        await store.refreshFolders({ silent: true });
        expect(store.folders).toEqual([]);
    });

    it('clears static-list cache before refresh', async() => {
        Api.getFolders.mockResolvedValue({ folders: [] });
        const store = makeStore();
        await store.refreshFolders({ silent: true });
        expect(cacheClear).toHaveBeenCalledTimes(1);
    });

    it('skips static-list cache clear when skipCacheClear is true', async() => {
        Api.getFolders.mockResolvedValue({ folders: [] });
        const store = makeStore();
        await store.refreshFolders({ silent: true, skipCacheClear: true });
        expect(cacheClear).not.toHaveBeenCalled();
    });

    it('ignores stale success results from an older request', async() => {
        const first = deferred();
        const second = deferred();
        Api.getFolders
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore();

        const firstRun = store.refreshFolders({ silent: false, params: { page: 1 } });
        const secondRun = store.refreshFolders({ silent: true, params: { page: 2 } });

        second.resolve({ folders: [{ id: 2, name: 'Newer' }] });
        await secondRun;
        expect(store.folders).toEqual([{ id: 2, name: 'Newer' }]);

        first.resolve({ folders: [{ id: 1, name: 'Older' }] });
        await firstRun;
        expect(store.folders).toEqual([{ id: 2, name: 'Newer' }]);
        expect(store.isLoading).toBe(false);
    });

    it('ignores stale errors from an older request', async() => {
        const first = deferred();
        const second = deferred();
        Api.getFolders
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore();

        const firstRun = store.refreshFolders({ silent: true });
        const secondRun = store.refreshFolders({ silent: true });

        second.resolve({ folders: [{ id: 2 }] });
        await secondRun;

        first.reject(new Error('older failed'));
        await expect(firstRun).rejects.toThrow('older failed');
        expect(store.error).toBeNull();
        expect(store.folders).toEqual([{ id: 2 }]);
    });

    it('keeps loading true until the latest non-silent request finishes', async() => {
        const first = deferred();
        const second = deferred();
        Api.getFolders
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore();

        const firstRun = store.refreshFolders({ silent: false });
        const secondRun = store.refreshFolders({ silent: false });
        expect(store.isLoading).toBe(true);

        first.resolve({ folders: [{ id: 1 }] });
        await firstRun;
        expect(store.isLoading).toBe(true);

        second.resolve({ folders: [{ id: 2 }] });
        await secondRun;
        expect(store.isLoading).toBe(false);
    });

    it('does not leave loading stuck when a silent refresh supersedes a visible one', async() => {
        const first = deferred();
        const second = deferred();
        Api.getFolders
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);
        const store = makeStore();

        const firstRun = store.refreshFolders({ silent: false });
        const secondRun = store.refreshFolders({ silent: true });
        expect(store.isLoading).toBe(true);

        second.resolve({ folders: [{ id: 2 }] });
        await secondRun;
        expect(store.isLoading).toBe(true);

        first.resolve({ folders: [{ id: 1 }] });
        await firstRun;
        expect(store.isLoading).toBe(false);
        expect(store.folders).toEqual([{ id: 2 }]);
    });
});

describe('navigationModule — openFolder', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('sets openId', async() => {
        Api.savePreference.mockResolvedValue({});
        const store = makeStore();
        await store.openFolder(5);
        expect(store.openId).toBe(5);
    });

    it('calls applyFolderFilter with resetPage:true', async() => {
        Api.savePreference.mockResolvedValue({});
        const store = makeStore();
        await store.openFolder(3);
        expect(store.applyFolderFilter).toHaveBeenCalledWith(3, { resetPage: true });
    });

    it('persists open folder immediately', async() => {
        Api.savePreference.mockResolvedValue({});
        const store = makeStore();
        await store.openFolder(7);
        expect(Api.savePreference).toHaveBeenCalledWith('open_folder_id', 7);
    });

    it('shows an upload-session notice when navigating away during active upload', async() => {
        Api.savePreference.mockResolvedValue({});
        const store = makeStore({
            isUploading: true,
            uploadLockedFolder: 11,
            folders: [{ id: 11, name: 'Uploads' }],
        });

        await store.openFolder(22);

        expect(store.notify).toHaveBeenCalledWith(
            'info',
            'Upload is still running in "Uploads". The view will return there when it finishes.',
            { key: 'upload-session', duration: 0 }
        );
    });

    it('expands all ancestors of the opened folder ([internal] regression control)', async() => {
        Api.savePreference.mockResolvedValue({});
        // дерево 1 → 2 → 3; ветка свёрнута (как при клике по папке из «Избранного»)
        const store = makeStore({
            folders: [
                { id: 1, parentId: 0 },
                { id: 2, parentId: 1 },
                { id: 3, parentId: 2 },
            ],
            collapsedIds: { 1: true, 2: true },
        });

        await store.openFolder(3);

        // предки раскрыты → узел 3 теперь отрисуется, focus/scroll попадут в цель
        expect(store.collapsedIds).toEqual({});
    });

    it('deferred: loads ancestor children before rendering so the node exists', async() => {
        Api.savePreference.mockResolvedValue({});
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

        await store.openFolder(3);

        // догрузка предков по цепочке от корня: 1, затем 2
        expect(loadFolderChildren.mock.calls.map((c) => c[0])).toEqual([1, 2]);
    });

    it('scrolls the opened folder node into view', async() => {
        Api.savePreference.mockResolvedValue({});
        document.body.innerHTML = '<div data-folder-id="4"></div>';
        const node = document.querySelector('[data-folder-id="4"]');
        node.scrollIntoView = jest.fn();
        node.focus = jest.fn();
        const store = makeStore({ folders: [{ id: 4, parentId: 0 }] });

        await store.openFolder(4);

        expect(node.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });
    });

    it('clears item selection state when opening another folder', async() => {
        Api.savePreference.mockResolvedValue({});
        const selection = { reset: jest.fn() };
        getMediaFrame.mockReturnValue({
            state: () => ({
                get: (key) => (key === 'selection' ? selection : null),
            }),
        });
        document.body.innerHTML = `
            <div class="attachment selected" data-id="41"></div>
            <input id="cb-select-all-1" type="checkbox" checked>
            <input id="cb-select-all-2" type="checkbox" checked>
            <input type="checkbox" name="media[]" value="41" checked>
            <input type="checkbox" name="post[]" value="42" checked>
        `;
        const store = makeStore({
            selected: [41, 42],
        });

        await store.openFolder(7);

        expect(selection.reset).toHaveBeenCalled();
        expect(store.selected).toEqual([]);
        expect(document.querySelector('.attachment[data-id="41"]').classList.contains('selected')).toBe(false);
        expect(document.querySelector('#cb-select-all-1').checked).toBe(false);
        expect(document.querySelector('#cb-select-all-2').checked).toBe(false);
        expect(document.querySelector('input[name="media[]"][value="41"]').checked).toBe(false);
        expect(document.querySelector('input[name="post[]"][value="42"]').checked).toBe(false);
    });
});

describe('navigationModule — applyFolderFilter media frame', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('sets both status and post_status for trash in media-frame collections', () => {
        const set = jest.fn();
        const unset = jest.fn();
        const fetch = jest.fn();
        getMediaFrame.mockReturnValue({
            content: {
                get: () => ({
                    collection: {
                        fetch,
                        props: { set, unset },
                    },
                }),
            },
        });

        const store = makeStore();
        navigationModule.applyFolderFilter.call(store, 999);

        expect(unset).toHaveBeenCalledWith('status', { silent: true });
        expect(unset).toHaveBeenCalledWith('post_status', { silent: true });
        expect(set).toHaveBeenCalledWith({ status: 'trash', post_status: 'trash' });
        expect(fetch).toHaveBeenCalledWith({ reset: true });
    });

    it('clears trash statuses and sets folder params for normal folders in media-frame collections', () => {
        const set = jest.fn();
        const unset = jest.fn();
        const fetch = jest.fn();
        getMediaFrame.mockReturnValue({
            content: {
                get: () => ({
                    collection: {
                        fetch,
                        props: { set, unset },
                    },
                }),
            },
        });

        const store = makeStore();
        navigationModule.applyFolderFilter.call(store, 12);

        expect(unset).toHaveBeenCalledWith('status', { silent: true });
        expect(unset).toHaveBeenCalledWith('post_status', { silent: true });
        expect(set).toHaveBeenCalledWith({ plathix_folder: 12 });
        expect(fetch).toHaveBeenCalledWith({ reset: true });
    });

    it('falls back to library.fetch({ reset: true }) when content collection is unavailable', () => {
        const set = jest.fn();
        const unset = jest.fn();
        const fetch = jest.fn();
        getMediaFrame.mockReturnValue({
            content: {
                get: () => null,
            },
            state: () => ({
                get: (key) => (key === 'library' ? {
                    fetch,
                    props: { set, unset },
                } : null),
            }),
        });

        const store = makeStore();
        navigationModule.applyFolderFilter.call(store, 12);

        expect(set).toHaveBeenCalledWith({ plathix_folder: 12 });
        expect(fetch).toHaveBeenCalledWith({ reset: true });
    });
});

describe('navigationModule — applyFolderFilter race safety ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.useFakeTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    it('does not let a stale retry cycle overwrite a newer synchronous filter application', () => {
        // Первый вызов: props ещё не готовы (frame есть, но collection/library без props) —
        // applyFolderFilter уходит в фоновый _retryMediaFrameFolderFilter (retry-поллинг).
        getMediaFrame.mockReturnValue({
            content: { get: () => ({ collection: null }) },
            state: () => ({ get: () => null }),
        });

        const store = makeStore();
        navigationModule.applyFolderFilter.call(store, 11);

        // Второй вызов (папка сменилась ДО того, как первый retry нашёл props): на этот раз
        // props сразу доступны — applyFolderFilter применяет фильтр синхронно.
        const set = jest.fn();
        const unset = jest.fn();
        const fetch = jest.fn();
        getMediaFrame.mockReturnValue({
            content: {
                get: () => ({
                    collection: { fetch, props: { set, unset, get: () => 0 } },
                }),
            },
        });
        navigationModule.applyFolderFilter.call(store, 22);

        expect(set).toHaveBeenCalledWith({ plathix_folder: 22 });

        // Дать первому (устаревшему) retry-циклу шанс досчитать все попытки — он не должен
        // найти props и применить фильтр для папки 11 поверх уже актуального состояния 22,
        // потому что _mediaFrameRetryToken был инвалидирован вторым вызовом.
        jest.advanceTimersByTime(20 * 150 + 100);

        expect(set).toHaveBeenCalledTimes(1);
        expect(set).not.toHaveBeenCalledWith({ plathix_folder: 11 });
    });
});

describe('navigationModule — refreshMediaFrame ([internal])', () => {
    beforeEach(() => jest.clearAllMocks());

    it('calls library._requery(true), not the broken .fetch() path', () => {
        const requery = jest.fn();
        const fetch = jest.fn();
        getMediaFrame.mockReturnValue({
            content: { get: () => null },
            state: () => ({
                get: (key) => (key === 'library' ? { _requery: requery, fetch } : null),
            }),
        });

        const store = makeStore();
        navigationModule.refreshMediaFrame.call(store);

        expect(requery).toHaveBeenCalledWith(true);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('does nothing when no media frame is available', () => {
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        expect(() => navigationModule.refreshMediaFrame.call(store)).not.toThrow();
    });

    it('swallows errors from a library without _requery (defensive, no throw)', () => {
        getMediaFrame.mockReturnValue({
            content: { get: () => null },
            state: () => ({ get: () => ({}) }),
        });
        const store = makeStore();

        expect(() => navigationModule.refreshMediaFrame.call(store)).not.toThrow();
    });
});

// ---------------------------------------------------------------------------
// [internal] — onAttachmentDeleted (previously uncovered)
// ---------------------------------------------------------------------------

describe('navigationModule — onAttachmentDeleted', () => {
    beforeEach(() => jest.clearAllMocks());

    it('patches the open folder count in place via patchFolder on success', async() => {
        const store = makeStore({
            openId: 3,
            folders: [{ id: 3, name: 'Current', count: 4 }, { id: 7, name: 'Other', count: 1 }],
        });
        const originalFolders = store.folders;
        Api.getFolderCount.mockResolvedValue(2);

        navigationModule.onAttachmentDeleted.call(store);
        await Promise.resolve();
        await Promise.resolve();

        expect(store.folders).toBe(originalFolders); // in-place — same reference
        expect(store.folders.find((f) => f.id === 3).count).toBe(2);
        expect(store.folders.find((f) => f.id === 7).count).toBe(1); // untouched sibling
    });

    it('does nothing when the open folder is not in this.folders', () => {
        const store = makeStore({ openId: 999, folders: [] });
        expect(() => navigationModule.onAttachmentDeleted.call(store)).not.toThrow();
        expect(Api.getFolderCount).not.toHaveBeenCalled();
    });

    it('swallows API failure without throwing (existing .catch behavior)', async() => {
        const store = makeStore({
            openId: 3,
            folders: [{ id: 3, name: 'Current', count: 4 }],
        });
        Api.getFolderCount.mockRejectedValue(new Error('network'));

        expect(() => navigationModule.onAttachmentDeleted.call(store)).not.toThrow();
        await Promise.resolve();
        await Promise.resolve();

        expect(store.folders[0].count).toBe(4); // unchanged on failure
    });
});
