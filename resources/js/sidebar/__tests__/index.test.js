describe('plathixInit grid bootstrap order', () => {
    beforeEach(() => {
        jest.resetModules();
        jest.useFakeTimers();
        document.body.innerHTML = '';
        window.Plathix = {
            openId: 7,
            caps: { canAssign: false, canManage: false },
            screenKind: 'static',
        };
        window.wp = {
            media: {
                frame: {
                    content: {
                        get: () => ({
                            collection: {
                                props: {},
                            },
                        }),
                    },
                },
            },
        };
    });

    afterEach(() => {
        jest.useRealTimers();
        delete window.Plathix;
        delete window.wp;
        delete window.Alpine;
        delete window.__PlathixApiReady;
    });

    it('starts the initial static grid filter before refreshFolders resolves', async () => {
        const applyFolderFilter = jest.fn();
        let resolveRefresh;
        const refreshFolders = jest.fn(() => new Promise((resolve) => {
            resolveRefresh = resolve;
        }));
        const store = { openId: 7, applyFolderFilter, refreshFolders };
        const bootstrapStaticSidebar = jest.fn();
        const bootstrapModalSidebar = jest.fn();
        const installModalMediaPatches = jest.fn();
        const bindUploadCompleteEvents = jest.fn();
        const bindAttachmentDeleteEvents = jest.fn();
        const enableAttachmentDnD = jest.fn();
        const initStaticListNavigation = jest.fn();
        const doAction = jest.fn();
        const initInfiniteScroll = jest.fn();
        const attachFrame = jest.fn();
        const installPublicApi = jest.fn();
        const alpineData = jest.fn();

        Object.defineProperty(document, 'readyState', {
            configurable: true,
            value: 'loading',
        });

        jest.doMock('alpinejs', () => ({
            store: jest.fn(() => store),
            data: alpineData,
            start: jest.fn(),
        }));
        jest.doMock('../hooks.js', () => ({ doAction }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../components/FolderTree.js', () => ({ folderTree: jest.fn() }));
        jest.doMock('../components/ContextMenu.js', () => ({ contextMenuComponent: jest.fn() }));
        jest.doMock('../components/BulkActions.js', () => ({ bulkActionsComponent: jest.fn() }));
        jest.doMock('../static-bootstrap.js', () => ({ bootstrapStaticSidebar }));
        jest.doMock('../modal-bootstrap.js', () => ({
            bootstrapModalSidebar,
            installModalMediaPatches,
        }));
        jest.doMock('../dnd.js', () => ({
            enableAttachmentDnD,
        }));
        jest.doMock('../upload-events.js', () => ({ bindUploadCompleteEvents }));
        jest.doMock('../attachment-events.js', () => ({ bindAttachmentDeleteEvents, bindSelectedMediaCountEvents: jest.fn() }));
        jest.doMock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));
        jest.doMock('../runtime.js', () => ({
            getPostType: jest.fn(() => 'attachment'),
            isUploadScreen: jest.fn(() => true),
            isStaticScreen: jest.fn(() => true),
            shouldUseMediaFrameFiltering: jest.fn(() => true),
            shouldUseStaticListFiltering: jest.fn(() => false),
            getFilterStrategy: jest.fn(() => 'media-frame'),
            getScreenKind: jest.fn(() => 'static'),
            getRuntime: jest.fn(() => ({ infiniteScroll: false })),
            getFeatures: jest.fn(() => ({ dnd: true, uploadSync: true })),
        }));
        jest.doMock('../infinite-scroll.js', () => ({
            infiniteScrollManager: {
                init: initInfiniteScroll,
                attachFrame,
            },
        }));
        jest.doMock('../static-list/index.js', () => ({ initStaticListNavigation }));
        jest.doMock('../state.js', () => ({
            hasStateFlag: jest.fn(() => false),
            setStateFlag: jest.fn(),
        }));
        jest.doMock('../public-api.js', () => ({ installPublicApi }));

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });

        document.dispatchEvent(new Event('DOMContentLoaded'));
        jest.runOnlyPendingTimers();
        await Promise.resolve();

        // [internal]: билдер уехал в PRO целиком. sidebar его БОЛЬШЕ НЕ регистрирует —
        // Alpine.data('shortcodeBuilder') предоставляет PRO-бандл shortcode-builder.js, который
        // энкьюится рядом на медиатеке. Инвариант [internal] переопределён этим пакетом.
        document.dispatchEvent(new Event('alpine:init'));
        expect(alpineData).not.toHaveBeenCalledWith('shortcodeBuilder', expect.any(Function));

        // FOM-101: инициатор форсит fetch через resetPage (первый boot: props пустой,
        // без resetPage guard мог бы пропустить наполнение грида).
        expect(applyFolderFilter).toHaveBeenCalledWith(7, { resetPage: true });

        // [internal] ([internal]/L8): при defer=false (deferFoldersBootstrap
        // не задан → falsy) дерево уже полное в сторе из bootstrap, поэтому init БОЛЬШЕ НЕ
        // делает лишний refreshFolders. Раньше здесь ассертился обратный (лишний) вызов.
        expect(refreshFolders).not.toHaveBeenCalled();

        // resolveRefresh остаётся объявленным, но не используется — refresh не вызывается.
        void resolveRefresh;
        await Promise.resolve();
    });

    it('lazy-режим (deferFoldersBootstrap=true) на init тоже НЕ делает полный refreshFolders — дерево догружается лениво', async () => {
        // Инвариант L8: снятие init-дубля не должно ВНЕЗАПНО добавить полный refresh в
        // lazy-режиме. Раньше блок был под !defer, т.е. при defer=true не срабатывал —
        // сохраняем это. Дерево при defer=true догружается loadFolderChildren по клику.
        window.Plathix.deferFoldersBootstrap = true;

        const applyFolderFilter = jest.fn();
        const refreshFolders = jest.fn(() => Promise.resolve({}));
        const store = { openId: 7, applyFolderFilter, refreshFolders };

        Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });

        jest.doMock('alpinejs', () => ({ store: jest.fn(() => store), data: jest.fn(), start: jest.fn() }));
        jest.doMock('../hooks.js', () => ({ doAction: jest.fn() }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../components/FolderTree.js', () => ({ folderTree: jest.fn() }));
        jest.doMock('../components/ContextMenu.js', () => ({ contextMenuComponent: jest.fn() }));
        jest.doMock('../components/BulkActions.js', () => ({ bulkActionsComponent: jest.fn() }));
        jest.doMock('../static-bootstrap.js', () => ({ bootstrapStaticSidebar: jest.fn() }));
        jest.doMock('../modal-bootstrap.js', () => ({ bootstrapModalSidebar: jest.fn(), installModalMediaPatches: jest.fn() }));
        jest.doMock('../dnd.js', () => ({ enableAttachmentDnD: jest.fn() }));
        jest.doMock('../upload-events.js', () => ({ bindUploadCompleteEvents: jest.fn() }));
        jest.doMock('../attachment-events.js', () => ({ bindAttachmentDeleteEvents: jest.fn(), bindSelectedMediaCountEvents: jest.fn() }));
        jest.doMock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));
        jest.doMock('../runtime.js', () => ({
            getPostType: jest.fn(() => 'attachment'),
            isUploadScreen: jest.fn(() => true),
            isStaticScreen: jest.fn(() => true),
            shouldUseMediaFrameFiltering: jest.fn(() => true),
            shouldUseStaticListFiltering: jest.fn(() => false),
            getFilterStrategy: jest.fn(() => 'media-frame'),
            getScreenKind: jest.fn(() => 'static'),
            getRuntime: jest.fn(() => ({ infiniteScroll: false })),
            getFeatures: jest.fn(() => ({ dnd: true, uploadSync: true })),
        }));
        jest.doMock('../infinite-scroll.js', () => ({ infiniteScrollManager: { init: jest.fn(), attachFrame: jest.fn() } }));
        jest.doMock('../static-list/index.js', () => ({ initStaticListNavigation: jest.fn() }));
        jest.doMock('../state.js', () => ({ hasStateFlag: jest.fn(() => false), setStateFlag: jest.fn() }));
        jest.doMock('../public-api.js', () => ({ installPublicApi: jest.fn() }));

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });

        document.dispatchEvent(new Event('DOMContentLoaded'));
        jest.runOnlyPendingTimers();
        await Promise.resolve();

        expect(refreshFolders).not.toHaveBeenCalled();
    });

    /**
     * [internal] ([internal]): сторож JS-контракта Free→PRO `window.Plathix.mediaGridClear`.
     *
     * Контракт зафиксирован осознанно в [internal] (2026-07-03): PRO-бандл
     * folder-upload не может очистить Free-приватный `_mem` собственным импортом — он
     * очистил бы пустую PRO-копию, — поэтому Free экспонирует сам `memClear` в глобал
     * (`index.js:67`), а PRO зовёт его как `window.Plathix?.mediaGridClear?.()`
     * (`plathixPro/resources/js/folder-upload/store.js:80`).
     *
     * До этого теста контракт не сторожился НИЧЕМ: переименование во Free не роняло ни один
     * тест ни в одном репо. typecheck слеп — поле объявлено опциональным
     * (`plathixPro/resources/js/types/globals.d.ts:39`) и зовётся через `?.()`, каталога
     * `tests/e2e` в PRO не существует. Дрейф деградировал бы в тихий no-op: после загрузки
     * папки кэш медиа-сетки не сбрасывался бы, пользователь видел бы устаревшую сетку.
     *
     * `media-grid-cache.js` намеренно НЕ мокается: иначе тест проверял бы имя мока, а не
     * реальный контракт. Здесь доезжает настоящий `memClear`.
     */
    it('exposes the real memClear as window.Plathix.mediaGridClear for PRO ([internal])', async () => {
        const store = { openId: 7, applyFolderFilter: jest.fn(), refreshFolders: jest.fn(() => Promise.resolve()) };

        jest.doMock('alpinejs', () => ({ store: jest.fn(() => store), data: jest.fn(), start: jest.fn() }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../state.js', () => ({ hasStateFlag: jest.fn(() => false), setStateFlag: jest.fn() }));

        // Тождество проверяется ВНУТРИ одного графа модулей: isolateModulesAsync даёт свой
        // экземпляр media-grid-cache.js, поэтому импорт снаружи вернул бы другую (идентичную,
        // но не тождественную) функцию — ложно-отрицательное сравнение по ссылке.
        let memClear;
        await jest.isolateModulesAsync(async () => {
            ({ memClear } = await import('../media-grid-cache.js'));
            await import('../index.js');
        });

        document.dispatchEvent(new Event('DOMContentLoaded'));
        jest.runOnlyPendingTimers();
        await Promise.resolve();

        expect(typeof window.Plathix.mediaGridClear).toBe('function');
        expect(window.Plathix.mediaGridClear).toBe(
            memClear,
        );
    });

    it('first open with no saved folder (openId=0) still applies the "All files" filter', async () => {
        // FOM-101 регрессия: раньше applyInitialStaticGridFilter при openId<=0 делал ранний
        // return и грид не синхронизировался (пустая медиатека на чистой установке). Теперь
        // openId=0 трактуется как «Все файлы» → applyFolderFilter(0, {resetPage:true}).
        const applyFolderFilter = jest.fn();
        const refreshFolders = jest.fn(() => Promise.resolve({}));
        const store = { openId: 0, applyFolderFilter, refreshFolders };

        Object.defineProperty(document, 'readyState', {
            configurable: true,
            value: 'loading',
        });

        jest.doMock('alpinejs', () => ({
            store: jest.fn(() => store),
            data: jest.fn(),
            start: jest.fn(),
        }));
        jest.doMock('../hooks.js', () => ({ doAction: jest.fn() }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../components/FolderTree.js', () => ({ folderTree: jest.fn() }));
        jest.doMock('../components/ContextMenu.js', () => ({ contextMenuComponent: jest.fn() }));
        jest.doMock('../components/BulkActions.js', () => ({ bulkActionsComponent: jest.fn() }));
        jest.doMock('../static-bootstrap.js', () => ({ bootstrapStaticSidebar: jest.fn() }));
        jest.doMock('../modal-bootstrap.js', () => ({
            bootstrapModalSidebar: jest.fn(),
            installModalMediaPatches: jest.fn(),
        }));
        jest.doMock('../dnd.js', () => ({ enableAttachmentDnD: jest.fn() }));
        jest.doMock('../upload-events.js', () => ({ bindUploadCompleteEvents: jest.fn() }));
        jest.doMock('../attachment-events.js', () => ({ bindAttachmentDeleteEvents: jest.fn(), bindSelectedMediaCountEvents: jest.fn() }));
        jest.doMock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));
        jest.doMock('../runtime.js', () => ({
            getPostType: jest.fn(() => 'attachment'),
            isUploadScreen: jest.fn(() => true),
            isStaticScreen: jest.fn(() => true),
            shouldUseMediaFrameFiltering: jest.fn(() => true),
            shouldUseStaticListFiltering: jest.fn(() => false),
            getFilterStrategy: jest.fn(() => 'media-frame'),
            getScreenKind: jest.fn(() => 'static'),
            getRuntime: jest.fn(() => ({ infiniteScroll: false })),
            getFeatures: jest.fn(() => ({ dnd: true, uploadSync: true })),
        }));
        jest.doMock('../infinite-scroll.js', () => ({ infiniteScrollManager: { init: jest.fn(), attachFrame: jest.fn() } }));
        jest.doMock('../static-list/index.js', () => ({ initStaticListNavigation: jest.fn() }));
        jest.doMock('../state.js', () => ({ hasStateFlag: jest.fn(() => false), setStateFlag: jest.fn() }));
        jest.doMock('../public-api.js', () => ({ installPublicApi: jest.fn() }));

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });

        document.dispatchEvent(new Event('DOMContentLoaded'));
        jest.runOnlyPendingTimers();
        await Promise.resolve();

        expect(applyFolderFilter).toHaveBeenCalledWith(0, { resetPage: true });
    });
});

describe('bindBeforeUnloadPersistence', () => {
    let originalSendBeacon;
    let originalAddEventListener;
    let registeredHandlers;

    beforeEach(() => {
        jest.resetModules();
        originalSendBeacon = navigator.sendBeacon;
        navigator.sendBeacon = jest.fn();
        registeredHandlers = [];
        originalAddEventListener = window.addEventListener.bind(window);
        window.addEventListener = (type, handler, ...rest) => {
            registeredHandlers.push({ type, handler });
            originalAddEventListener(type, handler, ...rest);
        };
        window.Plathix = {
            openId: 5,
            restUrl: 'https://example.com/wp-json/plathix/v1/',
            restNonce: 'abc123',
            caps: { canAssign: false, canManage: false },
            screenKind: 'static',
        };
    });

    afterEach(() => {
        navigator.sendBeacon = originalSendBeacon;
        window.addEventListener = originalAddEventListener;
        registeredHandlers.forEach(({ type, handler }) => {
            window.removeEventListener(type, handler);
        });
        delete window.Plathix;
        delete window.Alpine;
        delete window.__PlathixApiReady;
    });

    function buildMocks({ isUploading = false, openId = 5 } = {}) {
        const store = {
            openId,
            isUploading,
            applyFolderFilter: jest.fn(),
            refreshFolders: jest.fn(() => Promise.resolve({})),
        };
        jest.doMock('alpinejs', () => ({
            store: jest.fn(() => store),
            data: jest.fn(),
            start: jest.fn(),
        }));
        jest.doMock('../hooks.js', () => ({ doAction: jest.fn() }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../components/FolderTree.js', () => ({ folderTree: jest.fn() }));
        jest.doMock('../components/ContextMenu.js', () => ({ contextMenuComponent: jest.fn() }));
        jest.doMock('../components/BulkActions.js', () => ({ bulkActionsComponent: jest.fn() }));
        jest.doMock('../static-bootstrap.js', () => ({ bootstrapStaticSidebar: jest.fn() }));
        jest.doMock('../modal-bootstrap.js', () => ({ bootstrapModalSidebar: jest.fn(), installModalMediaPatches: jest.fn() }));
        jest.doMock('../dnd.js', () => ({ enableAttachmentDnD: jest.fn() }));
        jest.doMock('../upload-events.js', () => ({ bindUploadCompleteEvents: jest.fn() }));
        jest.doMock('../attachment-events.js', () => ({ bindAttachmentDeleteEvents: jest.fn(), bindSelectedMediaCountEvents: jest.fn() }));
        jest.doMock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));
        jest.doMock('../runtime.js', () => ({
            getPostType: jest.fn(() => 'attachment'),
            isUploadScreen: jest.fn(() => false),
            isStaticScreen: jest.fn(() => true),
            shouldUseMediaFrameFiltering: jest.fn(() => true),
            shouldUseStaticListFiltering: jest.fn(() => false),
            getFilterStrategy: jest.fn(() => 'media-frame'),
            getScreenKind: jest.fn(() => 'static'),
            getRuntime: jest.fn(() => ({ infiniteScroll: false })),
            getFeatures: jest.fn(() => ({ dnd: true, uploadSync: true })),
        }));
        jest.doMock('../infinite-scroll.js', () => ({ infiniteScrollManager: { init: jest.fn(), attachFrame: jest.fn() } }));
        jest.doMock('../static-list/index.js', () => ({ initStaticListNavigation: jest.fn() }));
        jest.doMock('../state.js', () => ({ hasStateFlag: jest.fn(() => false), setStateFlag: jest.fn() }));
        jest.doMock('../public-api.js', () => ({ installPublicApi: jest.fn() }));
        return store;
    }

    it('никогда не вызывает sendBeacon при beforeunload', async () => {
        buildMocks({ openId: 5 });
        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));
        window.dispatchEvent(new Event('beforeunload'));
        expect(navigator.sendBeacon).not.toHaveBeenCalled();
    });

    it('вызывает preventDefault при beforeunload если isUploading', async () => {
        buildMocks({ isUploading: true });
        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));
        const event = new Event('beforeunload', { cancelable: true });
        event.preventDefault = jest.fn();
        window.dispatchEvent(event);
        expect(event.preventDefault).toHaveBeenCalled();
    });

    it('handler не вызывает preventDefault если isUploading false', async () => {
        // Перехватываем последний зарегистрированный beforeunload handler напрямую
        let capturedHandler;
        const origAdd = window.addEventListener.bind(window);
        window.addEventListener = (type, fn, ...rest) => {
            if (type === 'beforeunload') capturedHandler = fn;
            origAdd(type, fn, ...rest);
        };

        buildMocks({ isUploading: false });
        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));

        window.addEventListener = origAdd;
        expect(capturedHandler).toBeDefined();

        const event = new Event('beforeunload');
        event.preventDefault = jest.fn();
        capturedHandler(event);
        expect(event.preventDefault).not.toHaveBeenCalled();
    });
});

describe('plathixInit no bootstrap path matched ([internal])', () => {
    beforeEach(() => {
        jest.resetModules();
        document.body.innerHTML = '';
        window.Plathix = { openId: 5, caps: { canAssign: false, canManage: false }, screenKind: 'static' };
    });

    afterEach(() => {
        delete window.Plathix;
        delete window.Alpine;
        delete window.__PlathixApiReady;
    });

    function mockFallbackBranchDeps({ bootstrapStaticList, bootstrapStaticGrid, bootstrapModal, doActionMock, store }) {
        jest.doMock('alpinejs', () => ({ store: jest.fn(() => store), data: jest.fn(), start: jest.fn() }));
        jest.doMock('../hooks.js', () => ({ doAction: doActionMock }));
        jest.doMock('../store.js', () => ({ sidebarStore: store }));
        jest.doMock('../components/FolderTree.js', () => ({ folderTree: jest.fn() }));
        jest.doMock('../components/ContextMenu.js', () => ({ contextMenuComponent: jest.fn() }));
        jest.doMock('../components/BulkActions.js', () => ({ bulkActionsComponent: jest.fn() }));
        jest.doMock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));
        jest.doMock('../media-grid-cache.js', () => ({ memClear: jest.fn() }));
        jest.doMock('../public-api.js', () => ({ installPublicApi: jest.fn() }));
        jest.doMock('../bootstrap-static-list.js', () => ({ bootstrapStaticList }));
        jest.doMock('../bootstrap-static-grid.js', () => ({ bootstrapStaticGrid }));
        jest.doMock('../bootstrap-modal.js', () => ({ bootstrapModal }));
        jest.doMock('../url-utils.js', () => ({ stripInitialFolderParamForMediaFrame: jest.fn() }));
        jest.doMock('../runtime.js', () => ({
            getPostType: jest.fn(() => 'attachment'),
            shouldUseMediaFrameFiltering: jest.fn(() => false),
            shouldUseStaticListFiltering: jest.fn(() => false),
            isStaticScreen: jest.fn(() => false),
            getFilterStrategy: jest.fn(() => 'url'),
            getScreenKind: jest.fn(() => 'static'),
        }));
        jest.doMock('../state.js', () => ({ hasStateFlag: jest.fn(() => false), setStateFlag: jest.fn() }));
    }

    it('вызывает doAction("plathix.sidebarBootstrapFallback") и не вызывает ни один из трёх bootstrap-путей, если ни один предикат не совпал ([internal])', async () => {
        const store = { openId: 5, applyFolderFilter: jest.fn(), refreshFolders: jest.fn(() => Promise.resolve({})) };
        const bootstrapStaticList = jest.fn();
        const bootstrapStaticGrid = jest.fn();
        const bootstrapModal = jest.fn();
        const doActionMock = jest.fn();

        mockFallbackBranchDeps({ bootstrapStaticList, bootstrapStaticGrid, bootstrapModal, doActionMock, store });

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await Promise.resolve();

        expect(bootstrapStaticList).not.toHaveBeenCalled();
        expect(bootstrapStaticGrid).not.toHaveBeenCalled();
        expect(bootstrapModal).not.toHaveBeenCalled();
        expect(doActionMock).toHaveBeenCalledWith('plathix.sidebarBootstrapFallback', { filterStrategy: 'url', screenKind: 'static' });
    });

    it('НЕ логирует console.warn, если window.Plathix.debug не установлен ([internal])', async () => {
        const store = { openId: 5, applyFolderFilter: jest.fn(), refreshFolders: jest.fn(() => Promise.resolve({})) };
        const doActionMock = jest.fn();

        mockFallbackBranchDeps({
            bootstrapStaticList: jest.fn(),
            bootstrapStaticGrid: jest.fn(),
            bootstrapModal: jest.fn(),
            doActionMock,
            store,
        });

        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await Promise.resolve();

        expect(warnSpy).not.toHaveBeenCalled();
        expect(doActionMock).toHaveBeenCalled();

        warnSpy.mockRestore();
    });

    it('логирует явный console.warn, если window.Plathix.debug === true ([internal])', async () => {
        window.Plathix.debug = true;
        const store = { openId: 5, applyFolderFilter: jest.fn(), refreshFolders: jest.fn(() => Promise.resolve({})) };
        const doActionMock = jest.fn();

        mockFallbackBranchDeps({
            bootstrapStaticList: jest.fn(),
            bootstrapStaticGrid: jest.fn(),
            bootstrapModal: jest.fn(),
            doActionMock,
            store,
        });

        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

        await jest.isolateModulesAsync(async () => {
            await import('../index.js');
        });
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await Promise.resolve();

        expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('filterStrategy=url'));

        warnSpy.mockRestore();
    });
});
