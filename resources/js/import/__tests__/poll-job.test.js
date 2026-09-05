/**
 * [internal] (переоткрытие, четвёртая причина): REST GET jobs/{id}, который опрашивал
 * poll-job.js раньше, никогда не существовал в PHP (rest_no_route на живом стенде). Реальный
 * диспатч job'а идёт через admin-ajax.php (wp_ajax_plathix_import) — опрос статуса должен
 * идти тем же транспортом (wp_ajax_plathix_import_status), не REST.
 */
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

describe('pollJob — admin-ajax transport ([internal])', () => {
    afterEach(() => {
        jest.restoreAllMocks();
    });

    it('возвращает result сразу при первом же complete-ответе', async () => {
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

    it('повторяет запрос, пока статус running, и завершается на complete', async () => {
        const fetchMock = jest.fn()
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'running' } }))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'running' } }))
            .mockResolvedValueOnce(jsonResponse({ success: true, data: { status: 'complete', result: { moved: 7 } } }));
        global.fetch = fetchMock;

        const result = await pollJob(1, makeSettings(), t, jest.fn(), 60000, 1);

        expect(result).toEqual({ moved: 7 });
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('бросает честную ошибку немедленно при response.status === 403 (invalid_nonce/permissions), без retry-допуска', async () => {
        // [internal]: AjaxGuard::require()/Nonce::verify_or_die() дают success:false ТОЛЬКО
        // вместе с HTTP 403 (invalid_nonce / insufficient permissions) — это терминальный
        // auth-отказ, не транспортный сбой; повторный опрос с тем же nonce не изменит исход.
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: false, data: { message: 'Job not found.' } }, false, 403)
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Job not found.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('допускает до 4 подряд транспортных сбоев опроса (network reject/429/невалидный JSON) и продолжает до complete', async () => {
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

    it('фатализирует с честным сообщением после 5 подряд транспортных сбоев опроса', async () => {
        const fetchMock = jest.fn().mockRejectedValue(new Error('network error'));
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn(), 60000, 1)).rejects.toThrow(
            'Could not check import status — connection is unstable. Please wait or try again later.'
        );
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('сбрасывает счётчик транспортных сбоев на успешном опросе (running), не копит их через несколько успешных итераций', async () => {
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

    it('бросает честную ошибку при статусе failed', async () => {
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: true, data: { status: 'failed' } })
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Import failed.');
    });

    it('бросает честную ошибку при статусе not_found', async () => {
        const fetchMock = jest.fn().mockResolvedValueOnce(
            jsonResponse({ success: true, data: { status: 'not_found' } })
        );
        global.fetch = fetchMock;

        await expect(pollJob(1, makeSettings(), t, jest.fn())).rejects.toThrow('Import failed.');
    });
});
