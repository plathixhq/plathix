describe('prevents concurrent state changes', () => {
    /** @type {Array<{ resolve: Function, signal: AbortSignal }>} */
    let pendingFetches;
    /** @type {typeof import('../api.js').Api} */
    let Api;

    beforeEach(() => {





        jest.resetModules();
        jest.doMock('../runtime.js', () => ({
            getRuntime: jest.fn(() => ({ restUrl: 'https://example.test/wp-json/plathix/v1/', nonce: 'n' })),
            getPostType: jest.fn(() => 'attachment'),
        }));
        Api = require('../api.js').Api;

        pendingFetches = [];
        global.fetch = jest.fn((_url, options) => {
            return new Promise((resolve, reject) => {
                const entry = { resolve, signal: options.signal };
                options.signal?.addEventListener('abort', () => {
                    const error = new Error('The operation was aborted.');
                    error.name = 'AbortError';
                    reject(error);
                });
                pendingFetches.push(entry);
            });
        });
    });

    afterEach(() => {
        delete global.fetch;
        jest.dontMock('../runtime.js');
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        const first = Api.savePreference('open_folder_id', 1);


        first.catch(() => {});

        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        const second = Api.savePreference('open_folder_id', 2);
        second.catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(true);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const promise = Api.savePreference('open_folder_id', 5);
        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        pendingFetches[0].resolve({ ok: true, json: async () => ({ success: true }) });
        await expect(promise).resolves.toEqual({ success: true });
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        Api.savePreference('open_folder_id', 1).catch(() => {});
        Api.savePreference('some_other_pref', 'x').catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(false);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });
});

describe('prevents concurrent state changes', () => {
    /** @type {Array<{ resolve: Function, signal: AbortSignal }>} */
    let pendingFetches;
    /** @type {typeof import('../api.js').Api} */
    let Api;

    beforeEach(() => {



        jest.resetModules();
        jest.doMock('../runtime.js', () => ({
            getRuntime: jest.fn(() => ({ restUrl: 'https://example.test/wp-json/plathix/v1/', nonce: 'n' })),
            getPostType: jest.fn(() => 'attachment'),
        }));
        Api = require('../api.js').Api;

        pendingFetches = [];
        global.fetch = jest.fn((_url, options) => {
            return new Promise((resolve, reject) => {
                const entry = { resolve, signal: options.signal };
                options.signal?.addEventListener('abort', () => {
                    const error = new Error('The operation was aborted.');
                    error.name = 'AbortError';
                    reject(error);
                });
                pendingFetches.push(entry);
            });
        });
    });

    afterEach(() => {
        delete global.fetch;
        jest.dontMock('../runtime.js');
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        const first = Api.saveFavorites([1]);


        first.catch(() => {});

        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        const second = Api.saveFavorites([1, 2]);
        second.catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(true);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const promise = Api.saveFavorites([5]);
        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        pendingFetches[0].resolve({ ok: true, json: async () => ({ success: true }) });
        await expect(promise).resolves.toEqual({ success: true });
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const first = Api.saveFavorites([1]);
        first.catch(() => {});
        const second = Api.saveFavorites([1, 2]);
        second.catch(() => {});


        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();


        Api.saveFavorites([1, 2, 3]).catch(() => {});
        expect(pendingFetches[1].signal.aborted).toBe(true);
        expect(pendingFetches[2].signal.aborted).toBe(false);
    });
});
