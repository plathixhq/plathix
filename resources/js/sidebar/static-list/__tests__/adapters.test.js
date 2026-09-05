import { UploadListAdapter } from '../adapters/upload-list.js';

jest.mock('../selectors.js', () => ({
    SEL_LIST: '#the-list',
    SEL_TABLENAV_TOP: '.tablenav.top',
    SEL_TABLENAV_BOTTOM: '.tablenav.bottom',
    SEL_VIEWS: '.subsubsub',
}));

jest.mock('../dom.js', () => ({
    extractZones: jest.fn(() => ({})),
    safeReplace: jest.fn(() => false),
    parseFragment: jest.fn(() => null),
}));

// ------------------------------------------------------------------
// UploadListAdapter
// ------------------------------------------------------------------

describe('UploadListAdapter — canHandle', () => {
    let adapter;

    beforeEach(() => {
        adapter = new UploadListAdapter();
        document.body.classList.remove('mode-list');
    });

    it('returns true for upload.php?mode=list URL', () => {
        expect(adapter.canHandle('http://example.com/wp-admin/upload.php?mode=list')).toBe(true);
    });

    it('returns true for upload.php when body has mode-list class', () => {
        document.body.classList.add('mode-list');
        expect(adapter.canHandle('http://example.com/wp-admin/upload.php')).toBe(true);
    });

    it('returns false for upload.php without mode=list and without class', () => {
        expect(adapter.canHandle('http://example.com/wp-admin/upload.php')).toBe(false);
    });

    it('returns false for edit.php', () => {
        expect(adapter.canHandle('http://example.com/wp-admin/edit.php?mode=list')).toBe(false);
    });

    it('returns false for malformed input', () => {
        expect(adapter.canHandle('not-a-url')).toBe(false);
    });
});

describe('UploadListAdapter — buildUrl', () => {
    let adapter;
    const base = 'http://example.com/wp-admin/upload.php?mode=list';

    beforeEach(() => {
        adapter = new UploadListAdapter();
        window.Plathix = { trashFolderId: 77 };
    });

    it('includes plathix_folder when folderId > 0 ([internal]: canonical URL must persist the folder)', () => {
        const url = new URL(adapter.buildUrl(base, 5));
        expect(url.searchParams.get('plathix_folder')).toBe('5');
    });

    it('removes plathix_folder when folderId = 0', () => {
        const url = new URL(adapter.buildUrl(`${base}&plathix_folder=3`, 0));
        expect(url.searchParams.has('plathix_folder')).toBe(false);
    });

    it('removes paged when resetPage=true', () => {
        const url = new URL(adapter.buildUrl(`${base}&paged=3`, 0, { resetPage: true }));
        expect(url.searchParams.has('paged')).toBe(false);
    });

    it('keeps paged when resetPage=false (default)', () => {
        const url = new URL(adapter.buildUrl(`${base}&paged=3`, 0));
        expect(url.searchParams.get('paged')).toBe('3');
    });

    it('encodes trash as attachment-filter only (status param removed per [internal])', () => {
        const url = new URL(adapter.buildUrl(base, 77));
        expect(url.searchParams.get('attachment-filter')).toBe('trash');
        expect(url.searchParams.has('status')).toBe(false);
        expect(url.searchParams.has('post_status')).toBe(false);
        expect(url.searchParams.has('plathix_folder')).toBe(false);
    });

    it('drops stale trash keys when navigating away from trash', () => {
        const url = new URL(adapter.buildUrl(`${base}&status=trash&attachment-filter=trash`, 5));
        expect(url.searchParams.has('status')).toBe(false);
        expect(url.searchParams.has('attachment-filter')).toBe(false);
    });
});

// ------------------------------------------------------------------
// buildParams
// ------------------------------------------------------------------

describe('UploadListAdapter — buildParams', () => {
    let adapter;
    beforeEach(() => { adapter = new UploadListAdapter(); });

    it('sets screen_base=upload and post_type=attachment', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list');
        expect(p.screen_base).toBe('upload');
        expect(p.post_type).toBe('attachment');
    });

    it('extracts folder_id as Number from plathix_folder', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&plathix_folder=7');
        expect(p.folder_id).toBe(7);
    });

    it('uses explicit folderId over clean URL', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list', 9);
        expect(p.folder_id).toBe(9);
    });

    it('returns folder_id=0 when no folder param', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list');
        expect(p.folder_id).toBe(0);
    });

    it('extracts paged as Number', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&paged=3');
        expect(p.paged).toBe(3);
    });

    it('defaults paged to 1 when absent', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list');
        expect(p.paged).toBe(1);
    });

    it('passes through orderby and order for query parity', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&orderby=date&order=ASC');
        expect(p.orderby).toBe('date');
        expect(p.order).toBe('ASC');
    });

    it('passes through post_mime_type for media type filter', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&post_mime_type=image');
        expect(p.post_mime_type).toBe('image');
    });

    it('passes through arbitrary third-party filter params', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&wc_category=shoes&my_filter=foo');
        expect(p.wc_category).toBe('shoes');
        expect(p.my_filter).toBe('foo');
    });

    it('strips security keys', () => {
        const p = adapter.buildParams('http://example.com/wp-admin/upload.php?mode=list&nonce=abc&_wpnonce=xyz');
        expect(p.nonce).toBeUndefined();
        expect(p._wpnonce).toBeUndefined();
    });
});
