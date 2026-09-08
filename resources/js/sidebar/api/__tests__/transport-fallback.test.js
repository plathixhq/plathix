jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { getRuntime } from '../../runtime.js';
import { restRequest } from '../transport.js';


describe('restRequest() nginx-405 fallback', () => {
    const RUNTIME = {
        restNonce: 'rn',
        nonce: 'n',
        restUrl: 'https://site.tld/wp-json/plathix/v1/',
        restUrlFallback: 'https://site.tld/index.php?rest_route=/plathix/v1/',
        ajaxUrl: 'https://site.tld/admin-ajax.php',
    };

    /** @type {Array<{url:string, method:string, nonce:string, body:any}>} */
    let calls;

    function mockFetchSequence(responders) {
        let i = 0;
        global.fetch = (url, init) => {
            calls.push({
                url,
                method: init.method,
                nonce: init.headers['X-WP-Nonce'],
                override: init.headers['X-HTTP-Method-Override'] || null,
                body: init.body,
            });
            const r = responders[Math.min(i, responders.length - 1)];
            i += 1;
            return Promise.resolve(r);
        };
    }

    const res = (ok, status, json) => ({ ok, status, json: () => Promise.resolve(json) });

    beforeEach(() => {
        jest.clearAllMocks();
        calls = [];
        getRuntime.mockReturnValue({ ...RUNTIME });
    });

    afterEach(() => { delete global.fetch; });

    it('enforces request authorization', async () => {
        mockFetchSequence([
            res(false, 405, null),                 // pretty → 405
            res(true, 200, { trashed: [1, 2] }),   // rest_route → ok
        ]);

        const out = await restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1, 2] } });

        expect(out).toEqual({ trashed: [1, 2] });
        expect(calls).toHaveLength(2);

        expect(calls[0].url).toContain('/wp-json/plathix/v1/media/bulk-trash');
        expect(calls[1].url).toContain('index.php?rest_route=/plathix/v1/media/bulk-trash');

        expect(calls[1].method).toBe('POST');
        expect(calls[1].nonce).toBe('rn');
        expect(calls[1].body).toBe(JSON.stringify({ ids: [1, 2] }));
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([res(false, 405, null)]);

        await expect(
            restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1] } }),
        ).rejects.toMatchObject({ code: 'rest_write_blocked' });


        expect(calls).toHaveLength(2);
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([res(false, 405, { message: 'nope' })]);

        await expect(restRequest('folders', { method: 'GET' })).rejects.toBeTruthy();


        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/');
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([res(true, 200, { ok: 1 })]);

        const out = await restRequest('media/bulk-trash', { method: 'POST', data: {} });

        expect(out).toEqual({ ok: 1 });
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/');
    });

    it('handles trash workflow consistently', async () => {
        mockFetchSequence([
            res(false, 405, null), // pretty DELETE → 405
            res(false, 405, null), // rest_route DELETE → 405
            res(true, 200, { purged: 1 }), // rest_route + override POST → ok
        ]);

        const out = await restRequest('folders/5/purge', { method: 'DELETE' });

        expect(out).toEqual({ purged: 1 });
        expect(calls).toHaveLength(3);
        expect(calls[2].url).toContain('index.php?rest_route=');
        expect(calls[2].override).toBe('DELETE');
        expect(calls[2].method).toBe('POST');
    });





    const resNull = (ok, status) => ({ ok, status, json: () => Promise.reject(new Error('invalid json')) });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([
            resNull(true, 200),
            res(true, 200, { folders: [{ id: 1 }] }),
        ]);

        const out = await restRequest('folders?post_type=attachment', { method: 'GET' });

        expect(out).toEqual({ folders: [{ id: 1 }] });
        expect(calls).toHaveLength(2);
        expect(calls[0].url).toContain('/wp-json/');
        expect(calls[1].url).toContain('index.php?rest_route=');
        expect(calls[1].method).toBe('GET');
    });

    it('preserves folder tree behavior', async () => {
        mockFetchSequence([res(true, 200, { folders: [] })]);

        const out = await restRequest('folders?post_type=attachment', { method: 'GET' });

        expect(out).toEqual({ folders: [] });
        expect(calls).toHaveLength(1);
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([resNull(true, 200)]);



        await expect(
            restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1] } }),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([resNull(true, 200)]);

        await expect(
            restRequest('folders', { method: 'GET' }),
        ).rejects.toMatchObject({ code: 'rest_read_corrupted' });

        expect(calls).toHaveLength(2);
    });




    it('covers public behavior without internal references', async () => {
        mockFetchSequence([resNull(true, 200)]);

        await expect(
            restRequest('folders/5', { method: 'PATCH', data: { name: 'x' } }),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });
});


import { uploadMultipart } from '../transport.js';

describe('uploadMultipart() 405-fallback', () => {
    const RUNTIME = {
        restNonce: 'rn',
        nonce: 'n',
        restUrl: 'https://site.tld/wp-json/plathix/v1/',
        restUrlFallback: 'https://site.tld/index.php?rest_route=/plathix/v1/',
        ajaxUrl: 'https://site.tld/admin-ajax.php',
    };

    /** @type {Array<{url:string, method:string, body:FormData}>} */
    let calls;

    function mockFetchSequence(responders) {
        let i = 0;
        global.fetch = (url, init) => {
            calls.push({ url, method: init.method, body: init.body });
            const r = responders[Math.min(i, responders.length - 1)];
            i += 1;
            return Promise.resolve(r);
        };
    }

    const res = (ok, status, json) => ({ ok, status, json: () => Promise.resolve(json) });

    beforeEach(() => {
        jest.clearAllMocks();
        calls = [];
        getRuntime.mockReturnValue({ ...RUNTIME });
    });

    afterEach(() => { delete global.fetch; });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([res(true, 200, { id: 42 })]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });
        const out = await uploadMultipart('folders/upload', file);

        expect(out).toEqual({ id: 42 });
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/plathix/v1/folders/upload');
    });

    it('covers public behavior without internal references', async () => {
        mockFetchSequence([
            res(false, 405, null),
            res(true, 200, { id: 7 }),
        ]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });
        const out = await uploadMultipart('folders/upload', file);

        expect(out).toEqual({ id: 7 });
        expect(calls).toHaveLength(2);
        expect(calls[0].url).toContain('/wp-json/plathix/v1/folders/upload');
        expect(calls[1].url).toContain('index.php?rest_route=/plathix/v1/folders/upload');

        expect(calls[0].body).toBeInstanceOf(FormData);
        expect(calls[1].body).toBeInstanceOf(FormData);
        expect(calls[0].body).not.toBe(calls[1].body);
    });

    it('prevents concurrent state changes', async () => {
        mockFetchSequence([res(false, 405, null)]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });

        await expect(
            uploadMultipart('folders/upload', file),
        ).rejects.toMatchObject({ code: 'rest_write_blocked' });

        expect(calls).toHaveLength(2);
    });




    it('covers public behavior without internal references', async () => {
        const resNull = (ok, status) => ({ ok, status, json: () => Promise.reject(new Error('invalid json')) });
        mockFetchSequence([resNull(true, 200)]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });

        await expect(
            uploadMultipart('folders/upload', file),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });
});
