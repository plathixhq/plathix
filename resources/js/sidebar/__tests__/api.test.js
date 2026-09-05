describe('Api.savePreference — [internal] (race при быстром переключении)', () => {
    /** @type {Array<{ resolve: Function, signal: AbortSignal }>} */
    let pendingFetches;
    /** @type {typeof import('../api.js').Api} */
    let Api;

    beforeEach(() => {
        // module-scope Map внутри api.js (_savePreferenceControllers) должна начинаться
        // пустой в каждом тесте — иначе контроллер, оставленный предыдущим тестом (never
        // settled, т.к. savePreference fire-and-forget по контракту), абортит запрос
        // следующего теста, у которого тот же key ('open_folder_id') и создаёт ложную связь
        // между независимыми тестами.
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

    it('абортит ещё не завершённый предыдущий запрос того же key при новом вызове', () => {
        const first = Api.savePreference('open_folder_id', 1);
        // Молчаливо игнорируем ожидаемый AbortError — так же, как вызывающий код
        // (store/navigation.js:150, static-list/manager.js:238) уже делает через .catch(() => {}).
        first.catch(() => {});

        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        const second = Api.savePreference('open_folder_id', 2);
        second.catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(true);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });

    it('одиночный вызов без конкуренции не абортится (no-regression)', async () => {
        const promise = Api.savePreference('open_folder_id', 5);
        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        pendingFetches[0].resolve({ ok: true, json: async () => ({ success: true }) });
        await expect(promise).resolves.toEqual({ success: true });
    });

    it('вызовы с разными key не абортят друг друга', () => {
        Api.savePreference('open_folder_id', 1).catch(() => {});
        Api.savePreference('some_other_pref', 'x').catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(false);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });
});

describe('Api.saveFavorites — [internal] ([internal], race при быстрых toggle)', () => {
    /** @type {Array<{ resolve: Function, signal: AbortSignal }>} */
    let pendingFetches;
    /** @type {typeof import('../api.js').Api} */
    let Api;

    beforeEach(() => {
        // Одиночный module-scope контроллер внутри api.js (_saveFavoritesController)
        // должен начинаться пустым в каждом тесте — тот же паттерн изоляции, что у
        // savePreference-блока выше.
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

    it('абортит ещё не завершённый предыдущий запрос при новом вызове', () => {
        const first = Api.saveFavorites([1]);
        // Ожидаемый AbortError глотается так же, как это делает вызывающий
        // favorites-store.js (AbortError-ветка catch).
        first.catch(() => {});

        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        const second = Api.saveFavorites([1, 2]);
        second.catch(() => {});

        expect(pendingFetches).toHaveLength(2);
        expect(pendingFetches[0].signal.aborted).toBe(true);
        expect(pendingFetches[1].signal.aborted).toBe(false);
    });

    it('одиночный вызов без конкуренции не абортится и резолвится (no-regression)', async () => {
        const promise = Api.saveFavorites([5]);
        expect(pendingFetches).toHaveLength(1);
        expect(pendingFetches[0].signal.aborted).toBe(false);

        pendingFetches[0].resolve({ ok: true, json: async () => ({ success: true }) });
        await expect(promise).resolves.toEqual({ success: true });
    });

    it('finally-очистка аборченного запроса не снимает чужой (более новый) контроллер', async () => {
        const first = Api.saveFavorites([1]);
        first.catch(() => {});
        const second = Api.saveFavorites([1, 2]);
        second.catch(() => {});

        // Дать settle'нуться finally первого (аборченного) запроса.
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        // Если бы finally первого обнулил контроллер второго, третий вызов НЕ абортил бы его.
        Api.saveFavorites([1, 2, 3]).catch(() => {});
        expect(pendingFetches[1].signal.aborted).toBe(true);
        expect(pendingFetches[2].signal.aborted).toBe(false);
    });
});
