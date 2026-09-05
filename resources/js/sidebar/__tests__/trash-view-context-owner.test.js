/**
 * [internal] ([internal], третий заход): isTrashViewActive() — владелец факта «текущий
 * видимый grid-контекст — Корзина», заменяет чтение live URL в момент клика.
 * Контракт: (1) снимок берётся один раз на исполнении модуля из initial URL;
 * (2) снимок НЕ меняется, если WP core стирает query из адресной строки после boot
 * (сам класс бага, который чинится этим пакетом); (3) снимок инвалидируется только
 * через событие plathix.folderFilterApplied в обе стороны.
 *
 * jest.isolateModules используется, потому что снимок вычисляется на module-scope при
 * первом импорте runtime.js — каждый кейс требует своего window.location/window.wp
 * ДО импорта, поэтому модуль переимпортируется в изоляции для каждого теста.
 */

function setLocation(href) {
    Object.defineProperty(window, 'location', {
        value: { href, origin: 'http://localhost' },
        writable: true,
        configurable: true,
    });
}

describe('isTrashViewActive ([internal], [internal] third pass)', () => {
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
            // WP core media-grid стирает query асинхронно после boot (live-подтверждено,
            // civm-vm-diag11/12b.mjs) — здесь моделируется мутацией window.location
            // напрямую, без перезагрузки модуля.
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
