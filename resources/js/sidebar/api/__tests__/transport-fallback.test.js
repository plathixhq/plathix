jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { getRuntime } from '../../runtime.js';
import { restRequest } from '../transport.js';

// [internal] (202): 405 на pretty write → one-shot повтор на rest_route base.
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

    it('POST 405 на pretty → повтор на rest_route base, тем же методом/nonce/телом', async () => {
        mockFetchSequence([
            res(false, 405, null),                 // pretty → 405
            res(true, 200, { trashed: [1, 2] }),   // rest_route → ok
        ]);

        const out = await restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1, 2] } });

        expect(out).toEqual({ trashed: [1, 2] });
        expect(calls).toHaveLength(2);
        // 1-й на pretty, 2-й на rest_route
        expect(calls[0].url).toContain('/wp-json/plathix/v1/media/bulk-trash');
        expect(calls[1].url).toContain('index.php?rest_route=/plathix/v1/media/bulk-trash');
        // метод/nonce/тело сохранены
        expect(calls[1].method).toBe('POST');
        expect(calls[1].nonce).toBe('rn');
        expect(calls[1].body).toBe(JSON.stringify({ ids: [1, 2] }));
    });

    it('one-shot: 405 и на pretty, и на rest_route → бросает, без 3-го запроса', async () => {
        mockFetchSequence([res(false, 405, null)]); // всегда 405

        await expect(
            restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1] } }),
        ).rejects.toMatchObject({ code: 'rest_write_blocked' });

        // ровно 2 попытки: pretty + rest_route, третьей нет
        expect(calls).toHaveLength(2);
    });

    it('GET не уводится на fallback (только write-методы)', async () => {
        mockFetchSequence([res(false, 405, { message: 'nope' })]);

        await expect(restRequest('folders', { method: 'GET' })).rejects.toBeTruthy();

        // GET: только 1 запрос (pretty), fallback не пробуется
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/');
    });

    it('нормальный сервер (200 на pretty) → fallback не трогается', async () => {
        mockFetchSequence([res(true, 200, { ok: 1 })]);

        const out = await restRequest('media/bulk-trash', { method: 'POST', data: {} });

        expect(out).toEqual({ ok: 1 });
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/');
    });

    it('DELETE 405 на обоих base → пробует method-override на rest_route (one-shot)', async () => {
        mockFetchSequence([
            res(false, 405, null), // pretty DELETE → 405
            res(false, 405, null), // rest_route DELETE → 405
            res(true, 200, { purged: 1 }), // rest_route + override POST → ok
        ]);

        const out = await restRequest('folders/5/purge', { method: 'DELETE' });

        expect(out).toEqual({ purged: 1 });
        expect(calls).toHaveLength(3);
        expect(calls[2].url).toContain('index.php?rest_route=');
        expect(calls[2].override).toBe('DELETE'); // override-заголовок выставлен
        expect(calls[2].method).toBe('POST');     // сам запрос идёт POST-ом
    });

    // [internal]: сервер вернул ok (200), но тело НЕ распарсилось в JSON
    // (parseJson → null). Наблюдалось на проде клиента A: GET folders → 200 + не-JSON → дерево
    // исчезало. Триггер строго json === null, только safe-методы, one-shot.
    // res(ok, status, null) моделирует ответ с телом, которое parseJson не разбирает.
    const resNull = (ok, status) => ({ ok, status, json: () => Promise.reject(new Error('invalid json')) });

    it('GET 200 + не-JSON тело на pretty → повтор на rest_route base, отдаёт данные', async () => {
        mockFetchSequence([
            resNull(true, 200),                    // pretty GET → 200 но тело битое
            res(true, 200, { folders: [{ id: 1 }] }), // rest_route → нормальный JSON
        ]);

        const out = await restRequest('folders?post_type=attachment', { method: 'GET' });

        expect(out).toEqual({ folders: [{ id: 1 }] });
        expect(calls).toHaveLength(2);
        expect(calls[0].url).toContain('/wp-json/');
        expect(calls[1].url).toContain('index.php?rest_route=');
        expect(calls[1].method).toBe('GET');
    });

    it('GET 200 + легитимный {folders:[]} → НЕ ретраит (пустой результат парсится)', async () => {
        mockFetchSequence([res(true, 200, { folders: [] })]);

        const out = await restRequest('folders?post_type=attachment', { method: 'GET' });

        expect(out).toEqual({ folders: [] });
        expect(calls).toHaveLength(1); // никакого fallback — тело валидное
    });

    it('POST 200 + не-JSON тело → НЕ ретраит (read-fallback только для safe-методов)', async () => {
        mockFetchSequence([resNull(true, 200)]);

        // [internal]: write+2xx+non-JSON больше не возвращает null молча — типизированный
        // throw, без повторного fetch (риск двойной мутации).
        await expect(
            restRequest('media/bulk-trash', { method: 'POST', data: { ids: [1] } }),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });

    it('GET 200 + не-JSON и на pretty, и на rest_route → бросает rest_read_corrupted (one-shot)', async () => {
        mockFetchSequence([resNull(true, 200)]); // всегда битое тело

        await expect(
            restRequest('folders', { method: 'GET' }),
        ).rejects.toMatchObject({ code: 'rest_read_corrupted' });

        expect(calls).toHaveLength(2); // pretty + rest_route, третьей попытки нет
    });

    // [internal]: write+2xx+non-JSON тело = сервер мог реально выполнить мутацию, но ответ
    // нечитаем. Повтор write запрещён (риск двойной мутации) — единственный честный исход
    // это typed throw, не тихий null и не read-style fallback retry.
    it('PATCH 200 + не-JSON тело → бросает rest_write_indeterminate, без повторного запроса', async () => {
        mockFetchSequence([resNull(true, 200)]);

        await expect(
            restRequest('folders/5', { method: 'PATCH', data: { name: 'x' } }),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });
});

// [internal] ([internal]): uploadMultipart 405-retry на restUrlFallback.
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

    it('200 на pretty → возвращает json, один запрос', async () => {
        mockFetchSequence([res(true, 200, { id: 42 })]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });
        const out = await uploadMultipart('folders/upload', file);

        expect(out).toEqual({ id: 42 });
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toContain('/wp-json/plathix/v1/folders/upload');
    });

    it('405 на pretty → retry на rest_route → 200 → возвращает json', async () => {
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
        // FormData пересоздаётся при retry — оба вызова получают новый объект FormData
        expect(calls[0].body).toBeInstanceOf(FormData);
        expect(calls[1].body).toBeInstanceOf(FormData);
        expect(calls[0].body).not.toBe(calls[1].body);
    });

    it('405 на pretty и на rest_route → бросает rest_write_blocked', async () => {
        mockFetchSequence([res(false, 405, null)]); // всегда 405

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });

        await expect(
            uploadMultipart('folders/upload', file),
        ).rejects.toMatchObject({ code: 'rest_write_blocked' });

        expect(calls).toHaveLength(2); // pretty + rest_route, третьей нет
    });

    // [internal] (тот же класс, что #695/#785): write+2xx+non-JSON тело больше не
    // возвращает null молча — типизированный throw, без повторного fetch (риск
    // повторной замены файла на сервере).
    it('200 + не-JSON тело → бросает rest_write_indeterminate, без повторного запроса', async () => {
        const resNull = (ok, status) => ({ ok, status, json: () => Promise.reject(new Error('invalid json')) });
        mockFetchSequence([resNull(true, 200)]);

        const file = new File(['data'], 'test.zip', { type: 'application/zip' });

        await expect(
            uploadMultipart('folders/upload', file),
        ).rejects.toMatchObject({ code: 'rest_write_indeterminate' });

        expect(calls).toHaveLength(1);
    });
});
