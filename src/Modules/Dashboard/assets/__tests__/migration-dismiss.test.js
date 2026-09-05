/**
 * [internal] (#117): BEHAVIORAL migration-dismiss ([internal]).
 *
 * Contract: migration banner удаляется ТОЛЬКО при success:true, и запрос несёт detected
 * source-ключ (из data-source баннера). На HTTP/JSON/network-failure баннер остаётся.
 *
 * Тестируется ПРОДОВАЯ initMigrationBannerDismiss из dashboard.js (реальный import) —
 * прежний synthetic-вариант (re-implement) заменён (#117). fetch мокается; серверный
 * persist покрыт HomeDashboardDismissBehaviorTest.php.
 */

import { initMigrationBannerDismiss } from '../dashboard.js';

// @wordpress/i18n — external (не в node_modules); virtual-мок для jsdom-import dashboard.js.
jest.mock('@wordpress/i18n', () => ({ _n: (single) => single, __: (s) => s }), { virtual: true });

/** Строит реальный migration-DOM с id/data-source, что читает продовый код. */
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
    it('удаляет баннер и отправляет detected source при success:true', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { banner, button } = buildDom('happyfiles');
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(false);
        // Запрос несёт именно detected source из data-source (контракт #119, per-source).
        const body = global.fetch.mock.calls[0][1].body;
        expect(body).toContain('source=happyfiles');
        expect(body).toContain('action=plathix_dismiss_migration');
    });
});

describe('initMigrationBannerDismiss — failure', () => {
    it('оставляет баннер при success:false', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: false }) });
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });

    it('оставляет баннер при HTTP 403', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 403, json: () => Promise.resolve({}) });
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });

    it('оставляет баннер при network-failure', async () => {
        global.fetch = jest.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        const { banner, button } = buildDom();
        initMigrationBannerDismiss();

        button.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(banner)).toBe(true);
        expect(button.disabled).toBe(false);
    });
});
