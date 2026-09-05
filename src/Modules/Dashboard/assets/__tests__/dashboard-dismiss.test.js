/**
 * [internal] (#117) + [internal] (per-card dismiss): BEHAVIORAL
 * onboarding-dismiss.
 *
 * Contract ([internal]): каждая карточка удаляется из DOM независимо от остальных, ТОЛЬКО
 * когда сервер подтвердил (success:true) для её card_id. Блок целиком исчезает только
 * когда карточек не осталось. На HTTP-error / success:false / network-failure карточка
 * остаётся + её кнопка re-enabled.
 *
 * Тестируется ПРОДОВАЯ функция initOnboardingDismiss из dashboard.js (реальный import),
 * а не копия — прежний synthetic-вариант (re-implement makePostDismiss) заменён (#117).
 * fetch мокается (сервер — отдельный слой, покрыт HomeDashboardDismissBehaviorTest.php).
 */

import { initOnboardingDismiss } from '../dashboard.js';

// dashboard.js импортирует @wordpress/i18n (_n) — мок, чтобы jsdom-import не тянул реальную зависимость.
// @wordpress/i18n — external (не в node_modules; предоставляется wp-scripts на рантайме).
// virtual: true позволяет замокать несуществующий на диске модуль для jsdom-import dashboard.js.
jest.mock('@wordpress/i18n', () => ({ _n: (single) => single, __: (s) => s }), { virtual: true });

/** Строит реальный onboarding-DOM (per-card кнопки) с id, которые читает продовый JS. */
function buildDom(cardIds = ['card-a']) {
    const cards = cardIds
        .map(
            (id) => `
        <div class="plathix-onboarding__card" data-card-id="${id}">
            <button class="plathix-onboarding__card-dismiss" type="button">Dismiss</button>
        </div>`
        )
        .join('');
    document.body.innerHTML = `<div id="plathix-onboarding-block">${cards}</div>`;

    return {
        block: document.getElementById('plathix-onboarding-block'),
        cards: Array.from(document.querySelectorAll('.plathix-onboarding__card')),
        buttons: Array.from(document.querySelectorAll('.plathix-onboarding__card-dismiss')),
    };
}

beforeEach(() => {
    window.PlathixDashboard = { ajaxUrl: '/wp-admin/admin-ajax.php', dismissNonce: 'abc' };
});

afterEach(() => {
    delete window.PlathixDashboard;
    delete global.fetch;
    document.body.innerHTML = '';
    jest.clearAllMocks();
});

describe('initOnboardingDismiss — success', () => {
    it('удаляет только дизмиснутую карточку, остальные и блок остаются', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { block, cards, buttons } = buildDom(['card-a', 'card-b']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(false);
        expect(document.body.contains(cards[1])).toBe(true);
        expect(document.body.contains(block)).toBe(true);
    });

    it('отправляет card_id дизмиснутой карточки в теле запроса', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        const body = global.fetch.mock.calls[0][1].body;
        expect(String(body)).toContain('card_id=card-a');
    });

    it('удаляет весь блок, когда дизмиснута последняя карточка', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { block, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(block)).toBe(false);
    });
});

describe('initOnboardingDismiss — failure', () => {
    it('оставляет карточку при success:false', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: false }) });
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });

    it('оставляет карточку при HTTP 403', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 403, json: () => Promise.resolve({}) });
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });

    it('оставляет карточку при network-failure', async () => {
        global.fetch = jest.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });
});
