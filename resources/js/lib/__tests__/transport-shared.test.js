import '../transport-shared.js';

// Файл публикует window.PlathixTransport как глобал (не ES-export) — тест взаимодействует
// с продовой точкой входа PRO-потребителей, не с внутренними функциями напрямую.
const { restRequest, postType, parseJson, refreshNonce, runtime } = window.PlathixTransport;

const RUNTIME = {
    restNonce: 'rn-old',
    nonce: 'n-old',
    restUrl: 'https://site.tld/wp-json/plathix/v1/',
    restUrlFallback: 'https://site.tld/index.php?rest_route=/plathix/v1/',
    ajaxUrl: 'https://site.tld/admin-ajax.php',
    postType: 'attachment',
};

let calls;

function mockFetchSequence(responders) {
    let i = 0;
    global.fetch = (url, init) => {
        calls.push({
            url,
            method: init.method,
            nonce: init.headers ? init.headers['X-WP-Nonce'] : undefined,
        });
        const r = responders[Math.min(i, responders.length - 1)];
        i += 1;
        return Promise.resolve(r);
    };
}

const res = (ok, status, json) => ({ ok, status, json: () => Promise.resolve(json) });

beforeEach(() => {
    calls = [];
    window.Plathix = { ...RUNTIME };
});

afterEach(() => {
    delete global.fetch;
    delete window.Plathix;
});

describe('window.PlathixTransport.restRequest — nonce-refresh retry ([internal] fixed contract)', () => {
    it('403 rest_cookie_invalid_nonce → refresh nonce → retry уходит с обновлённым X-WP-Nonce', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_cookie_invalid_nonce', message: 'expired' }),
            res(true, 200, { success: true, data: { nonce: 'n-new', restNonce: 'rn-new' } }),
            res(true, 200, { ok: 1 }),
        ]);

        const out = await restRequest('media', { method: 'GET' });

        expect(out).toEqual({ ok: 1 });
        expect(calls).toHaveLength(3);
        expect(calls[1].url).toBe(RUNTIME.ajaxUrl);
        expect(calls[2].nonce).toBe('rn-new');
    });

    // Мутационный контроль ([internal]): PRO-версия исторически требовала status===403,
    // Free-версия (до слияния) не проверяла статус вообще — этот тест фиксирует объединённый,
    // строгий контракт: НЕ-403 статус с тем же code не должен триггерить retry.
    it('rest_cookie_invalid_nonce БЕЗ статуса 403 → НЕ триггерит retry (строгий контракт)', async () => {
        mockFetchSequence([
            res(false, 401, { code: 'rest_cookie_invalid_nonce', message: 'expired, wrong status' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_cookie_invalid_nonce' });
        expect(calls).toHaveLength(1);
    });

    it('403 с другим code (rest_forbidden) → НЕ триггерит nonce-refresh/retry', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_forbidden', message: 'forbidden' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_forbidden' });
        expect(calls).toHaveLength(1);
    });
});

describe('window.PlathixTransport.restRequest — write-405-fallback', () => {
    it('405 на pretty write → one-shot retry на rest_route-base', async () => {
        mockFetchSequence([
            res(false, 405, null),
            res(true, 200, { created: true }),
        ]);

        const out = await restRequest('zip', { method: 'POST', data: { a: 1 } });

        expect(out).toEqual({ created: true });
        expect(calls).toHaveLength(2);
        expect(calls[0].url).toContain('wp-json');
        expect(calls[1].url).toContain('rest_route');
    });

    it('405 на обоих base для write → rest_write_blocked error', async () => {
        mockFetchSequence([
            res(false, 405, null),
            res(false, 405, null),
        ]);

        await expect(restRequest('zip', { method: 'POST' })).rejects.toMatchObject({ code: 'rest_write_blocked' });
        expect(calls).toHaveLength(2);
    });
});

describe('window.PlathixTransport.restRequest — read-non-JSON-fallback', () => {
    it('ok + non-JSON на pretty GET → one-shot retry на rest_route-base', async () => {
        mockFetchSequence([
            res(true, 200, null),
            res(true, 200, { folders: [] }),
        ]);

        const out = await restRequest('media', { method: 'GET' });

        expect(out).toEqual({ folders: [] });
        expect(calls).toHaveLength(2);
    });

    it('ok + non-JSON на обоих base для read → rest_read_corrupted error', async () => {
        mockFetchSequence([
            res(true, 200, null),
            res(true, 200, null),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_read_corrupted' });
    });

    it('ok + non-JSON для write → rest_write_indeterminate error, без повторного write', async () => {
        mockFetchSequence([
            res(true, 200, null),
        ]);

        await expect(restRequest('zip', { method: 'POST' })).rejects.toMatchObject({ code: 'rest_write_indeterminate' });
        expect(calls).toHaveLength(1);
    });
});

describe('window.PlathixTransport.postType/parseJson/refreshNonce', () => {
    it('runtime() возвращает window.Plathix целиком, пустой объект без него', () => {
        expect(runtime()).toBe(window.Plathix);
        delete window.Plathix;
        expect(runtime()).toEqual({});
    });

    it('postType() читает window.Plathix.postType, дефолт attachment', () => {
        expect(postType()).toBe('attachment');
        window.Plathix.postType = 'plathix_document';
        expect(postType()).toBe('plathix_document');
        delete window.Plathix.postType;
        expect(postType()).toBe('attachment');
    });

    it('parseJson() возвращает null при невалидном JSON вместо throw', async () => {
        const response = { json: () => Promise.reject(new Error('bad json')) };
        await expect(parseJson(response)).resolves.toBeNull();
    });

    it('refreshNonce() мутирует window.Plathix.nonce/restNonce и возвращает nonce', async () => {
        global.fetch = () => Promise.resolve(res(true, 200, { success: true, data: { nonce: 'n2', restNonce: 'rn2' } }));

        const out = await refreshNonce();

        expect(out).toBe('n2');
        expect(window.Plathix.nonce).toBe('n2');
        expect(window.Plathix.restNonce).toBe('rn2');
    });

    it('refreshNonce() бросает при неуспешном ответе', async () => {
        global.fetch = () => Promise.resolve(res(true, 200, { success: false, data: { message: 'session dead' } }));

        await expect(refreshNonce()).rejects.toThrow('session dead');
    });
});
