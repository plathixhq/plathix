jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { getRuntime } from '../../runtime.js';
import { restRequest, uploadMultipart } from '../transport.js';

// [internal]/201: retry после протухшего nonce должен триггериться реальным WP REST
// error-кодом (rest_cookie_invalid_nonce), не устаревшим 'invalid_nonce'.
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

    it('403 rest_cookie_invalid_nonce → refresh nonce → retry уходит с обновлённым X-WP-Nonce', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_cookie_invalid_nonce', message: 'expired' }), // pretty → 403
            res(true, 200, { success: true, data: { nonce: 'n-new', restNonce: 'rn-new' } }), // ajax refresh
            res(true, 200, { ok: 1 }), // retry → ok
        ]);

        const out = await restRequest('media', { method: 'GET' });

        expect(out).toEqual({ ok: 1 });
        expect(calls).toHaveLength(3);
        // 2-й запрос — nonce-refresh на ajaxUrl
        expect(calls[1].url).toBe(RUNTIME.ajaxUrl);
        // 3-й запрос — retry с обновлённым nonce, не старым
        expect(calls[2].nonce).toBe('rn-new');
        expect(calls[2].nonce).not.toBe(RUNTIME.restNonce);
    });

    it('403 с другим code (rest_forbidden) → НЕ триггерит nonce-refresh/retry', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_forbidden', message: 'forbidden' }),
        ]);

        await expect(restRequest('media', { method: 'GET' })).rejects.toMatchObject({ code: 'rest_forbidden' });

        // только 1 запрос — ни refresh, ни retry не произошли
        expect(calls).toHaveLength(1);
    });
});

// [internal]: uploadMultipart с runtimeOverride (вызывающий вне sidebar-контекста, напр.
// Replace UI) не должен пытаться nonce-retry — refreshNonce() мутирует только
// getRuntime()/window.Plathix, не runtimeOverride, поэтому повтор с override бессмыслен
// и может улететь в fetch(undefined) внутри refreshNonce().
describe('uploadMultipart() с runtimeOverride не триггерит nonce-refresh/retry', () => {
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
        // getRuntime() не должен вообще понадобиться при runtimeOverride — если вызовется,
        // это уже симптом бага (обращение к глобальному window.Plathix вместо override).
        getRuntime.mockReturnValue(undefined);
    });

    afterEach(() => { delete global.fetch; });

    it('403 rest_cookie_invalid_nonce + runtimeOverride → НЕ вызывает refreshNonce/retry, сразу throw', async () => {
        mockFetchSequence([
            res(false, 403, { code: 'rest_cookie_invalid_nonce', message: 'expired' }),
        ]);
        const file = new File(['x'], 'photo.jpg', { type: 'image/jpeg' });

        await expect(
            uploadMultipart('attachments/1/replace', file, { includePostType: false, runtimeOverride: OVERRIDE })
        ).rejects.toMatchObject({ code: 'rest_cookie_invalid_nonce' });

        // только 1 запрос — ни refresh (на ajaxUrl), ни retry не произошли
        expect(calls).toHaveLength(1);
    });
});
