jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { getRuntime } from '../../runtime.js';
import { restRequest, uploadMultipart } from '../transport.js';



describe('restRequest() nonce-refresh retry', () => {
    const RUNTIME = {
        restNonce: 'rn-old',
        nonce: 'n-old',
        restUrl: 'https://site.tld/wp-json/plathix/v1/',
        restUrlFallback: 'https://site.tld/index.php?rest_route=/plathix/v1/',
        ajaxUrl: 'https://site.tld/admin-ajax.php',
    };

    /** @type {Array<{url:string, method:string, nonce:string}>} */
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

    let runtime;

    beforeEach(() => {
        jest.clearAllMocks();
        calls = [];
        runtime = { ...RUNTIME };
        getRuntime.mockReturnValue(runtime);
    });

    afterEach(() => { delete global.fetch; });

    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_cookie_invalid_nonce', message: 'expired' }), // pretty → 403
            res(true, 200, { success: true, data: { nonce: 'n-new', restNonce: 'rn-new' } }), // ajax refresh
            res(true, 200, { ok: 1 }), // retry → ok
        ]);

        const out = await restRequest('media', { method: 'GET' });

        expect(out).toEqual({ ok: 1 });
        expect(calls).toHaveLength(3);

        expect(calls[1].url).toBe(RUNTIME.ajaxUrl);

        expect(calls[2].nonce).toBe('rn-new');
        expect(calls[2].nonce).not.toBe(RUNTIME.restNonce);
    });

    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_forbidden', message: 'forbidden' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_forbidden' });


        expect(calls).toHaveLength(1);
    });
});





describe('keeps upload links scoped to the active folder', () => {
    const OVERRIDE = {
        restUrl: 'https://other.tld/wp-json/plathix-pro/v1/',
        restNonce: 'override-nonce',
    };

    let calls;

    function mockFetchSequence(responders) {
        let i = 0;
        global.fetch = (url) => {
            calls.push({ url });
            const r = responders[Math.min(i, responders.length - 1)];
            i += 1;
            return Promise.resolve(r);
        };
    }

    const res = (ok, status, json) => ({ ok, status, json: () => Promise.resolve(json) });

    beforeEach(() => {
        jest.clearAllMocks();
        calls = [];


        getRuntime.mockReturnValue(undefined);
    });

    afterEach(() => { delete global.fetch; });

    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_cookie_invalid_nonce', message: 'expired' }),
        ]);
        const file = new File(['x'], 'photo.jpg', { type: 'image/jpeg' });

        await expect(
            uploadMultipart('attachments/1/replace', file, { includePostType: false, runtimeOverride: OVERRIDE })
        ).rejects.toMatchObject({ code: 'rest_cookie_invalid_nonce' });


        expect(calls).toHaveLength(1);
    });
});
