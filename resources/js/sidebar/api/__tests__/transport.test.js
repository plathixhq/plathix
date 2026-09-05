jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { getRuntime } from '../../runtime.js';
import { uploadFile } from '../transport.js';

describe('uploadFile()', () => {
    let lastInit;

    beforeEach(() => {
        jest.clearAllMocks();
        getRuntime.mockReturnValue({
            restNonce: 'nonce',
            wpMediaUrl: 'https://x/wp-json/wp/v2/media',
            ajaxUrl: 'https://x/admin-ajax.php',
        });
        global.fetch = function(url, init) {
            lastInit = init;
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ id: 42 }) });
        };
    });

    afterEach(() => { delete global.fetch; });

    it('тело запроса является FormData', async () => {
        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });
        await uploadFile(file);
        expect(lastInit.body.constructor.name).toBe('FormData');
    });

    it('FormData содержит поле file с переданным объектом File', async () => {
        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });
        await uploadFile(file);
        const got = lastInit.body.get('file');
        // Сравниваем атрибуты вместо toBe(file) — File является native jsdom объектом,
        // deepCyclicCopy Jest падает при попытке его сериализовать для вывода.
        expect(got.name).toBe(file.name);
        expect(got.size).toBe(file.size);
        expect(got.type).toBe(file.type);
    });

    it('заголовок Content-Disposition отсутствует', async () => {
        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });
        await uploadFile(file);
        expect(Object.keys(lastInit.headers)).not.toContain('Content-Disposition');
    });

    it('кириллическое имя файла не вызывает ошибку', async () => {
        const file = new File(['content'], 'фото-отпуск.jpg', { type: 'image/jpeg' });
        const result = await uploadFile(file);
        expect(result).toEqual({ id: 42 });
        expect(lastInit.body.constructor.name).toBe('FormData');
    });

    // [internal]: write+2xx+не-JSON тело раньше молча возвращалось как null — теперь
    // симметрично restRequest/uploadMultipart бросает типизированную ошибку.
    it('200 + не-JSON тело → бросает rest_write_indeterminate, не молчаливый null', async () => {
        global.fetch = () => Promise.resolve({
            ok: true,
            json: () => Promise.reject(new SyntaxError('Unexpected token <')),
        });
        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });

        await expect(uploadFile(file)).rejects.toMatchObject({ code: 'rest_write_indeterminate' });
    });
});
