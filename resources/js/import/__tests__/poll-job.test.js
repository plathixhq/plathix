

import { pollJob } from '../poll-job.js';

const t = (_key, fallback) => fallback;

function makeSettings(overrides = {}) {
    return {
        ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
        nonce: 'nonce123',
        ...overrides,
    };
}

function jsonResponse(body, ok = true, status = ok ? 200 : 500) {
    return { ok, status, json: async () => body };
}

describe('keeps REST transport behavior consistent under retry and error conditions', () => {
    afterEach(() => {
        jest.restoreAllMocks();
    });

    it('covers preset import and export behavior', async () => {
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: true, data: { status: 'complete', result: { moved: 5 } } })
        );
        global.fetch = fetchMock;

        const result = await pollJob(1, makeSettings(), t, jest.fn());

        expect(result).toEqual({ moved: 5 });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('https://example.test/wp-admin/admin-ajax.php');
        expect(options.method).toBe('POST');
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const fetchMock = jest.fn()
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'running' } }))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'running' } }))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'complete', result: { moved: 7 } } }));
        global.fetch = fetchMock;

        const result = await pollJob(1, makeSettings(), t, jest.fn(), 60000, 1);

        expect(result).toEqual({ moved: 7 });
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('enforces request authorization', async () => {



        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: false, data: { message: 'Job not found.' } }, false, 403)
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Job not found.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const fetchMock = jest.fn()
            .mockRejectedValueOnce(new Error('network error'))
            .mockResolvedValueOnce(jsonResponse({}, false, 429))
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => { throw new Error('bad json'); } })
            .mockResolvedValueOnce(jsonResponse({}, false, 503))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'complete', result: { moved: 3 } } }));
        global.fetch = fetchMock;

        const result = await pollJob(1, makeSettings(), t, jest.fn(), 60000, 1);

        expect(result).toEqual({ moved: 3 });
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('covers preset import and export behavior', async () => {
        const fetchMock = jest.fn().mockRejectedValue(new Error('network error'));
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn(), 60000, 1)).rejects.toThrow(
            'Could not check import status — connection is unstable. Please wait or try again later.'
        );
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('keeps store/selection state consistent across UI events', async () => {
        const fetchMock = jest.fn()
            .mockRejectedValueOnce(new Error('network error'))
            .mockRejectedValueOnce(new Error('network error'))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'running' } }))
            .mockRejectedValueOnce(new Error('network error'))
            .mockRejectedValueOnce(new Error('network error'))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'complete', result: { moved: 9 } } }));
        global.fetch = fetchMock;

        const result = await pollJob(1, makeSettings(), t, jest.fn(), 60000, 1);

        expect(result).toEqual({ moved: 9 });
        expect(fetchMock).toHaveBeenCalledTimes(6);
    });

    it('covers preset import and export behavior', async () => {
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: true, data: { status: 'failed' } })
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Import failed.');
    });

    it('covers preset import and export behavior', async () => {
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: true, data: { status: 'not_found' } })
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Import failed.');
    });
});
