/**
 * [internal]: queueImport() внутри index.js раньше вызывала голый response.json() без
 * try/catch — не-JSON ответ (HTML-страница ошибки от веб-сервера/WAF) всплывал как сырой
 * SyntaxError с текстом браузерного парсера вместо человекочитаемого сообщения. queueImport
 * не экспортируется (приватная функция IIFE-модуля) — единственный публичный вход это
 * DOM click на `.plathix-import-button`, поэтому тест ведётся через полную инициализацию
 * модуля и наблюдение за итоговым notice-текстом в statusNode.
 */

function setupDom() {
    document.body.innerHTML = `
        <div id="plathix-import-status" class="notice inline is-hidden"></div>
        <button class="plathix-import-button" data-adapter="demo">Import</button>
    `;
}

async function loadModuleAndClick() {
    jest.resetModules();
    setupDom();
    await import('../index.js');
    const button = document.querySelector('.plathix-import-button');
    button.click();
    // click handler асинхронный (async () => { await runImport(...) }); дождаться микротасков.
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('import/index.js queueImport() — non-JSON response handling ([internal])', () => {
    beforeEach(() => {
        window.PlathixSettings = {
            ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
            nonce: 'nonce123',
            i18n: {
                request_failed: 'Request failed.',
                import_failed: 'Import failed.',
            },
        };
    });

    afterEach(() => {
        delete window.PlathixSettings;
        delete global.fetch;
        document.body.innerHTML = '';
    });

    it('не-JSON ответ (HTML ошибка сервера) → человекочитаемое сообщение, не сырой SyntaxError', async () => {
        global.fetch = jest.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.reject(new SyntaxError('Unexpected token \'<\', "<!DOCTYPE "... is not valid JSON')),
        });

        await loadModuleAndClick();

        const statusNode = document.getElementById('plathix-import-status');
        expect(statusNode.className).toContain('notice-error');
        expect(statusNode.innerHTML).toContain('Request failed.');
        expect(statusNode.innerHTML).not.toContain('Unexpected token');
        expect(statusNode.innerHTML).not.toContain('SyntaxError');
    });

    it('валидный JSON success-ответ по-прежнему проходит нормально (regression)', async () => {
        global.fetch = jest.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ success: true, data: { jobId: 7 } }),
        });

        await loadModuleAndClick();

        // queueImport вернула jobId=7 > 0 — runImport переходит к pollJob (второй fetch,
        // на admin-ajax тем же mock'ом), в итоге не должно остаться голого текста ошибки
        // парсинга. Достаточно проверить, что первый вызов не упал с SyntaxError-текстом.
        const statusNode = document.getElementById('plathix-import-status');
        expect(statusNode.innerHTML).not.toContain('Unexpected token');
        expect(statusNode.innerHTML).not.toContain('SyntaxError');
    });
});
