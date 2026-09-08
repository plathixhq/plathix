





jest.mock('../../runtime.js', () => ({
    getRuntime: jest.fn(),
}));

import { getRuntime } from '../../runtime.js';
import { fetchListFragments } from '../fragments-request.js';

describe('fetchListFragments()', () => {
    let originalFetch;
    let originalAjaxurl;

    beforeEach(() => {
        jest.clearAllMocks();
        originalFetch = global.fetch;
        originalAjaxurl = window.ajaxurl;
        global.fetch = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, data: { items: [] } }),
        });
    });

    afterEach(() => {
        global.fetch = originalFetch;
        window.ajaxurl = originalAjaxurl;
        delete window.Plathix;
    });

    it('covers public behavior without internal references', async () => {
        getRuntime.mockReturnValue({ ajaxUrl: 'https://example.test/custom-ajax.php', nonce: 'n', listScreenAction: 'plathix_list_screen' });
        window.Plathix = { ajaxUrl: 'https://should-not-be-used.test/admin-ajax.php' };

        await fetchListFragments({}, undefined);

        expect(global.fetch).toHaveBeenCalledWith('https://example.test/custom-ajax.php', expect.any(Object));
    });

    it('covers public behavior without internal references', async () => {
        getRuntime.mockReturnValue({ ajaxurl: 'https://example.test/lowercase-ajax.php' });

        await fetchListFragments({}, undefined);

        expect(global.fetch).toHaveBeenCalledWith('https://example.test/lowercase-ajax.php', expect.any(Object));
    });

    it('covers public behavior without internal references', async () => {
        getRuntime.mockReturnValue({});
        window.ajaxurl = 'https://example.test/wp-core-ajax.php';

        await fetchListFragments({}, undefined);

        expect(global.fetch).toHaveBeenCalledWith('https://example.test/wp-core-ajax.php', expect.any(Object));
    });

    it('enforces request authorization', async () => {
        getRuntime.mockReturnValue({ ajaxUrl: 'https://example.test/admin-ajax.php', nonce: 'my-nonce', listScreenAction: 'custom_action' });

        await fetchListFragments({ page: 2, folder: 5, empty: '' }, undefined);

        const [, init] = global.fetch.mock.calls[0];
        const body = init.body;
        expect(body.get('action')).toBe('custom_action');
        expect(body.get('nonce')).toBe('my-nonce');
        expect(body.get('page')).toBe('2');
        expect(body.get('folder')).toBe('5');
        expect(body.has('empty')).toBe(false);
    });
});
