


function setLocation(href) {
    Object.defineProperty(window, 'location', {
        value: { href, origin: 'http://localhost' },
        writable: true,
        configurable: true,
    });
}

describe('handles trash workflow consistently', () => {
    let addedHandlers;

    beforeEach(() => {
        addedHandlers = [];
        window.Plathix = { trashFolderId: 99 };
        window.wp = {
            hooks: {
                addAction: jest.fn((name, ns, cb) => {
                    addedHandlers.push({ name, cb });
                }),
            },
        };
    });

    afterEach(() => {
        delete window.Plathix;
        delete window.wp;
        jest.resetModules();
    });

    function fireFolderFilterApplied(folderId) {
        const handler = addedHandlers.find((h) => h.name === 'plathix.folderFilterApplied');
        handler?.cb({ folderId });
    }

    it('snapshot: true when initial URL has attachment-filter=trash', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash&mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(true);
        });
    });

    it('snapshot: false when initial URL has no attachment-filter', () => {
        setLocation('http://localhost/wp-admin/upload.php?mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(false);
        });
    });

    it('survives URL erasure after boot — snapshot does not change when WP core strips the query (the bug class itself)', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash&mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');



            setLocation('http://localhost/wp-admin/upload.php');
            expect(isTrashViewActive()).toBe(true);
        });
    });

    it('invalidates to false when plathix.folderFilterApplied fires with a non-trash folderId', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash&mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(true);
            fireFolderFilterApplied(7);
            expect(isTrashViewActive()).toBe(false);
        });
    });

    it('invalidates to false when plathix.folderFilterApplied fires with folderId=0 ("All files")', () => {
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash&mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(true);
            fireFolderFilterApplied(0);
            expect(isTrashViewActive()).toBe(false);
        });
    });

    it('flips to true when plathix.folderFilterApplied fires with the trash folderId', () => {
        setLocation('http://localhost/wp-admin/upload.php?mode=grid');
        jest.isolateModules(() => {
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(false);
            fireFolderFilterApplied(99);
            expect(isTrashViewActive()).toBe(true);
        });
    });

    it('does not throw when window.wp.hooks is unavailable at import time', () => {
        delete window.wp;
        setLocation('http://localhost/wp-admin/upload.php?attachment-filter=trash&mode=grid');
        jest.isolateModules(() => {
            expect(() => require('../runtime.js')).not.toThrow();
            const { isTrashViewActive } = require('../runtime.js');
            expect(isTrashViewActive()).toBe(true);
        });
    });
});
