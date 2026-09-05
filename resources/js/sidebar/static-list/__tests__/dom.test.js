import { parseFragment, parseFragmentBySelector } from '../dom.js';

// Реалистичный topNav из WP display_tablenav('top'): hidden-inputs ПЕРЕД .tablenav.top.
// Именно из-за этого firstElementChild ломал пагинацию ([internal]).
const TOP_NAV_HTML =
    '<input type="hidden" id="_wpnonce" name="_wpnonce" value="abc123" />' +
    '<input type="hidden" name="_wp_http_referer" value="/wp-admin/upload.php" />' +
    '<div class="tablenav top">' +
    '  <div class="tablenav-pages"><span class="displaying-num">40 элементов</span>' +
    '  <span class="pagination-links">1 из 2</span></div>' +
    '</div>';

const BOTTOM_NAV_HTML =
    '<div class="tablenav bottom">' +
    '  <div class="tablenav-pages"><span class="pagination-links">1 из 2</span></div>' +
    '</div>';

describe('parseFragmentBySelector ([internal])', () => {
    it('extracts .tablenav.top even when hidden _wpnonce inputs precede it', () => {
        const el = parseFragmentBySelector(TOP_NAV_HTML, '.tablenav.top');
        expect(el).not.toBeNull();
        expect(el.className).toBe('tablenav top');
        expect(el.querySelector('.tablenav-pages')).not.toBeNull();
    });

    it('does NOT return the leading _wpnonce input (the old firstElementChild bug)', () => {
        const el = parseFragmentBySelector(TOP_NAV_HTML, '.tablenav.top');
        expect(el.tagName).not.toBe('INPUT');
        expect(el.id).not.toBe('_wpnonce');
    });

    it('extracts .tablenav.bottom (no nonce prefix)', () => {
        const el = parseFragmentBySelector(BOTTOM_NAV_HTML, '.tablenav.bottom');
        expect(el).not.toBeNull();
        expect(el.className).toBe('tablenav bottom');
    });

    it('returns null when the selector is not found (safe: live element stays)', () => {
        const el = parseFragmentBySelector('<div class="wp-filter">views</div>', '.subsubsub');
        expect(el).toBeNull();
    });

    it('regression: old parseFragment returns the wrong first element for topNav', () => {
        // Документирует корень бага: firstElementChild = _wpnonce input, не .tablenav.top.
        const wrong = parseFragment(TOP_NAV_HTML);
        expect(wrong.tagName).toBe('INPUT');
        expect(wrong.id).toBe('_wpnonce');
    });
});
