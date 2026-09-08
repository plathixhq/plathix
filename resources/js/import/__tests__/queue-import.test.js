


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

    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('covers preset import and export behavior', () => {
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

    it('covers public behavior without internal references', async () => {
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

    it('covers public behavior without internal references', async () => {
        global.fetch = jest.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ success: true, data: { jobId: 7 } }),
        });

        await loadModuleAndClick();




        const statusNode = document.getElementById('plathix-import-status');
        expect(statusNode.innerHTML).not.toContain('Unexpected token');
        expect(statusNode.innerHTML).not.toContain('SyntaxError');
    });
});
