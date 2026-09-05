/**
 * [internal] ([internal], #827): guardTrashUrl() — mutation-channel B,
 * восстанавливает attachment-filter=trash после ЧУЖОЙ (не нашей) мутации URL
 * через history.replaceState, когда isTrashViewActive() снапшот утверждает,
 * что мы всё ещё в Корзине. Контракт: (1) оборачивает нативный
 * window.history.replaceState один раз (idempotent); (2) не трогает URL, если
 * снапшот не в Trash; (3) не трогает URL, если attachment-filter=trash уже
 * присутствует после чужого вызова; (4) восстанавливает параметр отдельным
 * replaceState-вызовом, если он был стёрт.
 */

function setLocation(href) {
    Object.defineProperty(window, 'location', {
        value: { href, origin: 'http://localhost' },
        writable: true,
        configurable: true,
    });
}

describe('guardTrashUrl ([internal], [internal]/#827)', () => {
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

    it('REGRESSION [internal]: restores attachment-filter=trash after WP core strips it via replaceState while the snapshot is active', () => {
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
