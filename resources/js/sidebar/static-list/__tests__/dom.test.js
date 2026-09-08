import { parseFragment, parseFragmentBySelector } from '../dom.js';



const TOP_NAV_HTML =
    '<input type="hidden" id="_wpnonce" name="_wpnonce" value="abc123" />' +
    '<input type="hidden" name="_wp_http_referer" value="/wp-admin/upload.php" />' +
    '<div class="tablenav top">' +
    'Media item' +
    'Public-facing message unavailable.' +
    '</div>';

const BOTTOM_NAV_HTML =
    '<div class="tablenav bottom">' +
    'Public-facing message unavailable.' +
    '</div>';

describe('covers public behavior without internal references', () => {
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

        const wrong = parseFragment(TOP_NAV_HTML);
        expect(wrong.tagName).toBe('INPUT');
        expect(wrong.id).toBe('_wpnonce');
    });
});
