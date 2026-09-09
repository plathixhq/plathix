


import { initMigrationBannerDismiss } from '../dashboard.js';


jest.mock('@wordpress/i18n', () => ({ _n: (single) => single, __: (s) => s }), { virtual: true });


function buildDom(source = 'filebird') {
    document.body.innerHTML = `
        <div id="plathix-migration-banner" data-source="${source}">
            <button id="plathix-migration-dismiss" type="button">Dismiss</button>
        </div>`;
    return {
        banner: document.getElementById('plathix-migration-banner'),
        button: document.getElementById('plathix-migration-dismiss'),
    };
}

beforeEach(() => {
    window.PlathixDashboard = { ajaxUrl: '/wp-admin/admin-ajax.php', migrationDismissNonce: 'xyz' };
});

afterEach(() => {
    delete window.PlathixDashboard;
    delete global.fetch;
    document.body.innerHTML = '';
    jest.clearAllMocks();
});

describe('initMigrationBannerDismiss — success', () => {
    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { banner, button } = buildDom('happyfiles');
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(false);

        const body = global.fetch.mock.calls[0][1].body;
        expect(body).toContain('source=happyfiles');
        expect(body).toContain('action=plathix_dismiss_migration');
    });
});

describe('initMigrationBannerDismiss — failure', () => {
    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: false }) });
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });

    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 403, json: () => Promise.resolve({}) });
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });

    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });
});
