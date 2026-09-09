import '../transport-shared.js';



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

describe('enforces request authorization', () => {
    it('enforces request authorization', async () => {
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




    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 401, { code: 'rest_cookie_invalid_nonce', message: 'expired, wrong status' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_cookie_invalid_nonce' });
        expect(calls).toHaveLength(1);
    });

    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_forbidden', message: 'forbidden' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_forbidden' });
        expect(calls).toHaveLength(1);
    });
});

describe('window.PlathixTransport.restRequest — write-405-fallback', () => {
    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
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

    it('prevents concurrent state changes', async () => {
        mockFetchSequence([
            res(false, 405, null),
            res(false, 405, null),
        ]);

        await expect(restRequest('zip', { method: 'POST' })).rejects.toMatchObject({ code: 'rest_write_blocked' });
        expect(calls).toHaveLength(2);
    });
});

describe('window.PlathixTransport.restRequest — read-non-JSON-fallback', () => {
    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(true, 200, null),
            res(true, 200, { folders: [] }),
        ]);

        const out = await restRequest('media', { method: 'GET' });

        expect(out).toEqual({ folders: [] });
        expect(calls).toHaveLength(2);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(true, 200, null),
            res(true, 200, null),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_read_corrupted' });
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(true, 200, null),
        ]);

        await expect(restRequest('zip', { method: 'POST' })).rejects.toMatchObject({ code: 'rest_write_indeterminate' });
        expect(calls).toHaveLength(1);
    });
});

describe('keeps REST transport behavior consistent under retry and error conditions', () => {
    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(false, 404, { code: 'not_found', message: 'Job not found.' }),
        ]);

        await expect(restRequest('jobs/1', { method: 'GET' })).rejects.toMatchObject({
            code: 'not_found',
            httpStatus: 404,
        });
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(false, 503, null),
        ]);

        await expect(restRequest('jobs/1', { method: 'GET' })).rejects.toMatchObject({
            httpStatus: 503,
        });
    });

    it('prevents concurrent state changes', async () => {
        mockFetchSequence([
            res(false, 405, null),
            res(false, 405, null),
        ]);

        await expect(restRequest('zip', { method: 'POST' })).rejects.toMatchObject({
            code: 'rest_write_blocked',
            httpStatus: 405,
        });
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(true, 200, null),
            res(true, 200, null),
        ]);

        expect.assertions(2);
        try {
            await restRequest('media', { method: 'GET' });
        } catch (error) {
            expect(error.code).toBe('rest_read_corrupted');
            expect(error.httpStatus).toBeUndefined();
        }
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        mockFetchSequence([
            res(true, 200, null),
        ]);

        expect.assertions(2);
        try {
            await restRequest('zip', { method: 'POST' });
        } catch (error) {
            expect(error.code).toBe('rest_write_indeterminate');
            expect(error.httpStatus).toBeUndefined();
        }
    });
});

describe('window.PlathixTransport.postType/parseJson/refreshNonce', () => {
    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        expect(runtime()).toBe(window.Plathix);
        delete window.Plathix;
        expect(runtime()).toEqual({});
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        expect(postType()).toBe('attachment');
        window.Plathix.postType = 'plathix_document';
        expect(postType()).toBe('plathix_document');
        delete window.Plathix.postType;
        expect(postType()).toBe('attachment');
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const response = { json: () => Promise.reject(new Error('bad json')) };
        await expect(parseJson(response)).resolves.toBeNull();
    });

    it('enforces request authorization', async () => {
        global.fetch = () => Promise.resolve(res(true, 200, { success: true, data: { nonce: 'n2', restNonce: 'rn2' } }));

        const out = await refreshNonce();

        expect(out).toBe('n2');
        expect(window.Plathix.nonce).toBe('n2');
        expect(window.Plathix.restNonce).toBe('rn2');
    });

    it('enforces request authorization', async () => {
        global.fetch = () => Promise.resolve(res(true, 200, { success: false, data: { message: 'session dead' } }));

        await expect(refreshNonce()).rejects.toThrow('session dead');
    });
});
