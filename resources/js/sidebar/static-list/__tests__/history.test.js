


function setLocation(href) {
    Object.defineProperty(window, 'location', {
        value: { href, origin: 'http://localhost' },
        writable: true,
        configurable: true,
    });
}

describe('handles trash workflow consistently', () => {
    let nativeReplaceState;

    beforeEach(() => {
        nativeReplaceState = jest.fn();
        window.history.replaceState = nativeReplaceState;
    });

    afterEach(() => {
        jest.resetModules();
    });

    it('wraps window.history.replaceState exactly once (idempotent)', () => {
        jest.isolateModules(() => {
            const { guardTrashUrl } = require('../history.js');
            const isTrashActive = jest.fn(() => false);

            guardTrashUrl(isTrashActive);
            const wrapped = window.history.replaceState;
            guardTrashUrl(isTrashActive);

            expect(window.history.replaceState).toBe(wrapped);
        });
    });

    it('does not touch the URL when the owner snapshot says we are NOT in Trash', () => {
        setLocation('http://localhost/wp-admin/upload.php?mode=grid');
        jest.isolateModules(() => {
            const { guardTrashUrl } = require('../history.js');
            const isTrashActive = jest.fn(() => false);
            guardTrashUrl(isTrashActive);

            window.history.replaceState({}, '', 'http://localhost/wp-admin/upload.php?mode=grid');

            // native call once (the caller's own call) — no restoring second call
            expect(nativeReplaceState).toHaveBeenCalledTimes(1);
        });
    });

    it('does not touch the URL when attachment-filter=trash survives the foreign mutation', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash');
        jest.isolateModules(() => {
            const { guardTrashUrl } = require('../history.js');
            const isTrashActive = jest.fn(() => true);
            guardTrashUrl(isTrashActive);

            window.history.replaceState({}, '', 'http://localhost/wp-admin/upload.php?attachment-filter=trash&paged=2');

            expect(nativeReplaceState).toHaveBeenCalledTimes(1);
        });
    });

    it('handles trash workflow consistently', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash');
        jest.isolateModules(() => {
            const { guardTrashUrl } = require('../history.js');
            const isTrashActive = jest.fn(() => true);
            guardTrashUrl(isTrashActive);

            // Simulates WP core media-grid.js Backbone.history.start() stripping the
            // query into a bare URL — the wrapped replaceState must apply this call
            // via the native fn, THEN observe location no longer carries the param
            // and issue a restoring call.
            nativeReplaceState.mockImplementation((state, title, url) => {
                setLocation(url);
            });

            window.history.replaceState({}, '', 'http://localhost/wp-admin/upload.php');

            expect(nativeReplaceState).toHaveBeenCalledTimes(2);
            const restoreCallUrl = nativeReplaceState.mock.calls[1][2];
            expect(new URL(restoreCallUrl).searchParams.get('attachment-filter')).toBe('trash');
        });
    });

    it('does not throw on a malformed URL argument', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash');
        jest.isolateModules(() => {
            const { guardTrashUrl } = require('../history.js');
            const isTrashActive = jest.fn(() => true);
            guardTrashUrl(isTrashActive);

            // window.location.href itself becomes malformed relative parsing —
            // simulate by making native call leave location on something odd, then
            // ensure the wrapper does not throw.
            nativeReplaceState.mockImplementation(() => {
                setLocation('not a url');
            });

            expect(() => {
                window.history.replaceState({}, '', 'irrelevant');
            }).not.toThrow();
        });
    });
});



describe('handles trash workflow consistently', () => {






    let bindViewSwitchTrashHrefGuard;
    let isTrashActiveRef;

    beforeAll(() => {
        jest.isolateModules(() => {
            ({ bindViewSwitchTrashHrefGuard } = require('../history.js'));
        });
        isTrashActiveRef = { current: true };
        bindViewSwitchTrashHrefGuard(() => isTrashActiveRef.current);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    function makeViewSwitchLink(href) {
        const nav = document.createElement('div');
        nav.className = 'view-switch';
        const link = document.createElement('a');
        link.href = href;
        nav.appendChild(link);
        document.body.appendChild(nav);
        return link;
    }

    it('rewrites href to include attachment-filter=trash when Trash is active', () => {
        isTrashActiveRef.current = true;
        const link = makeViewSwitchLink('http://localhost/wp-admin/upload.php?mode=grid');

        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(new URL(link.href).searchParams.get('attachment-filter')).toBe('trash');
    });

    it('does not touch href when Trash is not active', () => {
        isTrashActiveRef.current = false;
        const link = makeViewSwitchLink('http://localhost/wp-admin/upload.php?mode=grid');

        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(new URL(link.href).searchParams.has('attachment-filter')).toBe(false);
    });

    it('does not duplicate attachment-filter when it is already present', () => {
        isTrashActiveRef.current = true;
        const link = makeViewSwitchLink('http://localhost/wp-admin/upload.php?mode=grid&attachment-filter=trash');

        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        const params = new URL(link.href).searchParams.getAll('attachment-filter');
        expect(params).toEqual(['trash']);
    });

    it('registers the click listener only once across repeated calls (idempotent)', () => {
        isTrashActiveRef.current = true;
        const spy = jest.fn(() => isTrashActiveRef.current);




        bindViewSwitchTrashHrefGuard(spy);
        const link = makeViewSwitchLink('http://localhost/wp-admin/upload.php?mode=grid');

        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(spy).not.toHaveBeenCalled();
        expect(new URL(link.href).searchParams.get('attachment-filter')).toBe('trash');
    });
});
