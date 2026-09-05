import {
    cacheClear,
    cacheEpoch,
    cacheGet,
    cacheInvalidateFolder,
    cacheInvalidateScreen,
    cacheSet,
    bindFolderMutationSlot,
} from '../cache.js';

function getData(params) {
    const entry = cacheGet(params);
    return entry ? entry.data : null;
}

describe('static-list cache', () => {
    beforeEach(() => {
        cacheClear();
        jest.restoreAllMocks();
    });

    it('retrieves cached data regardless of param key order', () => {
        cacheSet({ folder_id: 7, screen_base: 'upload', paged: 2 }, { ok: true }, cacheEpoch());

        expect(getData({ paged: 2, screen_base: 'upload', folder_id: 7 })).toEqual({ ok: true });
    });

    it('returns null after cacheClear', () => {
        cacheSet({ folder_id: 7, screen_base: 'upload' }, { ok: true }, cacheEpoch());

        cacheClear();

        expect(cacheGet({ folder_id: 7, screen_base: 'upload' })).toBeNull();
    });

    it('expires entries after TTL', () => {
        jest.spyOn(Date, 'now').mockReturnValue(1_000);
        cacheSet({ folder_id: 7, screen_base: 'upload' }, { ok: true }, cacheEpoch());

        expect(getData({ folder_id: 7, screen_base: 'upload' })).toEqual({ ok: true });

        Date.now.mockReturnValue(46_001);
        expect(cacheGet({ folder_id: 7, screen_base: 'upload' })).toBeNull();
    });

    it('invalidates a target folder and root folder entries, but keeps unrelated folders', () => {
        cacheSet({ folder_id: 7, screen_base: 'upload' }, { folder: 7 }, cacheEpoch());
        cacheSet({ folder_id: 0, screen_base: 'upload' }, { folder: 0 }, cacheEpoch());
        cacheSet({ folder_id: 9, screen_base: 'upload' }, { folder: 9 }, cacheEpoch());

        cacheInvalidateFolder(7);

        expect(cacheGet({ folder_id: 7, screen_base: 'upload' })).toBeNull();
        expect(cacheGet({ folder_id: 0, screen_base: 'upload' })).toBeNull();
        expect(getData({ folder_id: 9, screen_base: 'upload' })).toEqual({ folder: 9 });
    });

    it('invalidates all cache entries for a screen base only', () => {
        cacheSet({ folder_id: 7, screen_base: 'upload' }, { screen: 'upload-7' }, cacheEpoch());
        cacheSet({ folder_id: 0, screen_base: 'upload' }, { screen: 'upload-0' }, cacheEpoch());
        cacheSet({ folder_id: 7, screen_base: 'edit' }, { screen: 'edit-7' }, cacheEpoch());

        cacheInvalidateScreen('upload');

        expect(cacheGet({ folder_id: 7, screen_base: 'upload' })).toBeNull();
        expect(cacheGet({ folder_id: 0, screen_base: 'upload' })).toBeNull();
        expect(getData({ folder_id: 7, screen_base: 'edit' })).toEqual({ screen: 'edit-7' });
    });

    // [internal] — write-after-invalidate: fetch запущен ДО инвалидации, инвалидация
    // приходит, пока fetch летит, fetch резолвится и пишет устаревшие данные ПОСЛЕ того,
    // как инвалидация уже прошла. cacheSet теперь принимает эпоху, зафиксированную ДО
    // await, и не пишет, если эпоха успела смениться.
    it('REGRESSION [internal]: a write started before invalidation must not survive it', async () => {
        const params = { folder_id: 7, screen_base: 'upload' };
        let resolveFetch;
        const pendingFetch = new Promise((resolve) => {
            resolveFetch = resolve;
        });

        // имитация #loadFragments/prefetch: эпоха фиксируется ДО await, ключ ещё не в _store
        const epochAtFetchStart = cacheEpoch();
        const fetchPromise = pendingFetch.then((data) => {
            cacheSet(params, data, epochAtFetchStart);
            return data;
        });

        // пока fetch летит, мутация (upload/delete/move) инвалидирует папку 7
        cacheInvalidateFolder(7);

        // fetch резолвится ПОСЛЕ инвалидации, неся данные, прочитанные ДО мутации
        resolveFetch({ stale: true });
        await fetchPromise;

        expect(cacheGet(params)).toBeNull();
    });

    it('a write started AFTER the last invalidation still succeeds (no false negative)', async () => {
        const params = { folder_id: 7, screen_base: 'upload' };

        cacheInvalidateFolder(7);
        const epochAtFetchStart = cacheEpoch();
        const data = await Promise.resolve({ fresh: true });
        cacheSet(params, data, epochAtFetchStart);

        expect(getData(params)).toEqual({ fresh: true });
    });
});

describe('слот внешних мутаций папок ([internal])', () => {
    let handler;

    beforeEach(() => {
        cacheClear();
        handler = null;
        window.wp = {
            hooks: {
                addAction: (hook, ns, fn) => {
                    if (hook === 'plathix.foldersChanged') handler = fn;
                },
            },
        };
        bindFolderMutationSlot();
    });

    afterEach(() => {
        delete window.wp;
    });

    it('подписывается на plathix.foldersChanged', () => {
        expect(typeof handler).toBe('function');
    });

    it('чистит кэш названных папок и не трогает остальные', () => {
        cacheSet({ folder_id: 5, screen_base: 'edit' }, { s: 'edit-5' }, cacheEpoch());
        cacheSet({ folder_id: 9, screen_base: 'edit' }, { s: 'edit-9' }, cacheEpoch());
        cacheSet({ folder_id: 4, screen_base: 'upload' }, { s: 'upload-4' }, cacheEpoch());

        handler({ folderIds: [5, 9] });

        expect(cacheGet({ folder_id: 5, screen_base: 'edit' })).toBeNull();
        expect(cacheGet({ folder_id: 9, screen_base: 'edit' })).toBeNull();
        expect(getData({ folder_id: 4, screen_base: 'upload' })).toEqual({ s: 'upload-4' });
    });

    it('не падает на мусорном payload', () => {
        cacheSet({ folder_id: 5, screen_base: 'edit' }, { s: 'edit-5' }, cacheEpoch());

        expect(() => handler(undefined)).not.toThrow();
        expect(() => handler({})).not.toThrow();
        expect(() => handler({ folderIds: 'нет' })).not.toThrow();
        expect(() => handler({ folderIds: [null, 'abc'] })).not.toThrow();

        expect(getData({ folder_id: 5, screen_base: 'edit' })).toEqual({ s: 'edit-5' });
    });

    it('чистит записи независимо от screen_base — слот про папки, не про экраны', () => {
        cacheSet({ folder_id: 7, screen_base: 'upload' }, { s: 'u' }, cacheEpoch());
        cacheSet({ folder_id: 7, screen_base: 'edit' }, { s: 'e' }, cacheEpoch());

        handler({ folderIds: [7] });

        expect(cacheGet({ folder_id: 7, screen_base: 'upload' })).toBeNull();
        expect(cacheGet({ folder_id: 7, screen_base: 'edit' })).toBeNull();
    });
});
