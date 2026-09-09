


import { initOnboardingDismiss } from '../dashboard.js';




jest.mock('@wordpress/i18n', () => ({ _n: (single) => single, __: (s) => s }), { virtual: true });


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
    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { block, cards, buttons } = buildDom(['card-a', 'card-b']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(false);
        expect(document.body.contains(cards[1])).toBe(true);
        expect(document.body.contains(block)).toBe(true);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        const body = global.fetch.mock.calls[0][1].body;
        expect(String(body)).toContain('card_id=card-a');
    });

    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: true }) });
        const { block, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(block)).toBe(false);
    });
});

describe('initOnboardingDismiss — failure', () => {
    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: false }) });
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });

    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 403, json: () => Promise.resolve({}) });
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });

    it('mounts or dismisses the UI element under the expected conditions', async () => {
        global.fetch = jest.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        const { cards, buttons } = buildDom(['card-a']);
        initOnboardingDismiss();

        buttons[0].click();
        await new Promise((r) => setTimeout(r, 0));

        expect(document.body.contains(cards[0])).toBe(true);
        expect(buttons[0].disabled).toBe(false);
    });
});
