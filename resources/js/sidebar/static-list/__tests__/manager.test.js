jest.mock('alpinejs', () => ({ store: jest.fn() }));
jest.mock('../fragments-request.js', () => ({ fetchListFragments: jest.fn() }));
jest.mock('../../api.js', () => ({
    Api: {
        savePreference: jest.fn().mockResolvedValue({}),
    },
}));
jest.mock('../history.js', () => ({
    pushUrl: jest.fn(),
    replaceUrl: jest.fn(),
    onPopState: jest.fn(),
    removePopState: jest.fn(),
}));
// [internal] ([internal]): manager.js читает isTrashViewActive() вместо
// isTrashViewFromUrl(window.location.href) — снапшот владельца, не live URL.
jest.mock('../../runtime.js', () => ({
    isTrashViewActive: jest.fn(() => false),
}));

jest.mock('../adapters/upload-list.js', () => {
    const inst = {
        canHandle: jest.fn(),
        buildUrl: jest.fn(),
        buildParams: jest.fn(),
        applyZones: jest.fn(),
        applyFragments: jest.fn(),
    };
    return { UploadListAdapter: jest.fn(() => inst), _instance: inst };
});


import Alpine from 'alpinejs';
import { Api } from '../../api.js';
import { fetchListFragments } from '../fragments-request.js';
import { pushUrl, replaceUrl, onPopState } from '../history.js';
import { isTrashViewActive } from '../../runtime.js';
import { _instance as uploadInst } from '../adapters/upload-list.js';
import { StaticListNavigationManager } from '../manager.js';
import { cacheClear, cacheGet, cacheInvalidateFolder } from '../cache.js';

const UPLOAD_URL = 'http://localhost/wp-admin/upload.php?mode=list';
const LEGACY_UPLOAD_URL = 'http://localhost/wp-admin/upload.php?mode=list&plathix_folder=5';
const UPLOAD_URL_NO_FOLDER = 'http://localhost/wp-admin/upload.php?mode=list';

const MOCK_FRAGMENTS = { views: '', topNav: '', list: '', bottomNav: '' };
const MOCK_DATA = { fragments: MOCK_FRAGMENTS };
const CANONICAL_URL = 'http://localhost/wp-admin/upload.php?mode=list&orderby=date';
const MOCK_DATA_WITH_URL = { fragments: MOCK_FRAGMENTS, url: CANONICAL_URL };

function makeStore(overrides = {}) {
    return { openId: 0, ...overrides };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

beforeEach(() => {
    jest.clearAllMocks();
    cacheClear();

    Object.defineProperty(window, 'location', {
        value: { href: UPLOAD_URL_NO_FOLDER, origin: 'http://localhost', assign: jest.fn() },
        writable: true,
        configurable: true,
    });
    window.Plathix = { trashFolderId: 77 };
    isTrashViewActive.mockReturnValue(false);

    uploadInst.canHandle.mockReturnValue(false);
    uploadInst.buildParams.mockReturnValue({ screen_base: 'upload', post_type: 'attachment', folder_id: 5, paged: 1 });
    uploadInst.applyFragments.mockReturnValue(true);

});

// ------------------------------------------------------------------
// navigate — history
// ------------------------------------------------------------------

describe('navigate — history', () => {
    it('calls pushUrl when push=true (default)', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(pushUrl).toHaveBeenCalledWith(UPLOAD_URL, { plathixFolderId: 5 });
    });

    it('uses canonical URL from backend response when available', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA_WITH_URL);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(pushUrl).toHaveBeenCalledWith(CANONICAL_URL, { plathixFolderId: 5 });
    });

    it('falls back to frontend URL when backend returns no url', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA); // no .url field

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(pushUrl).toHaveBeenCalledWith(UPLOAD_URL, { plathixFolderId: 5 });
    });

    it('does NOT call pushUrl when push=false (popstate)', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { push: false, folderId: 5 });

        expect(pushUrl).not.toHaveBeenCalled();
        expect(replaceUrl).toHaveBeenCalledWith(UPLOAD_URL, { plathixFolderId: 5 });
    });
});

// ------------------------------------------------------------------
// navigate — fragment request
// ------------------------------------------------------------------

describe('navigate — fragment request', () => {
    it('calls fetchListFragments with params from adapter.buildParams', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(fetchListFragments).toHaveBeenCalledWith(
            uploadInst.buildParams.mock.results[0].value,
            expect.any(AbortSignal)
        );
    });

    it('calls adapter.applyFragments with fragments from response', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(uploadInst.applyFragments).toHaveBeenCalledWith(MOCK_FRAGMENTS);
    });

    it('ignores stale fragment responses from an older navigation', async () => {
        const first = deferred();
        const second = deferred();
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);

        const mgr = new StaticListNavigationManager();
        const firstRun = mgr.navigate(`${UPLOAD_URL}&paged=1`, { folderId: 5 });
        const secondRun = mgr.navigate(`${UPLOAD_URL}&paged=2`, { folderId: 6 });

        second.resolve({ fragments: { ...MOCK_FRAGMENTS, list: 'newer' } });
        await secondRun;

        first.resolve({ fragments: { ...MOCK_FRAGMENTS, list: 'older' } });
        await firstRun;

        expect(uploadInst.applyFragments).toHaveBeenCalledTimes(1);
        expect(uploadInst.applyFragments).toHaveBeenCalledWith({ ...MOCK_FRAGMENTS, list: 'newer' });
        expect(pushUrl).toHaveBeenCalledWith(`${UPLOAD_URL}&paged=2`, { plathixFolderId: 6 });
    });
});

// ------------------------------------------------------------------
// navigate — openId sync
// ------------------------------------------------------------------

describe('navigate — #syncOpenId', () => {
    it('updates store.openId from explicit folderId on clean URL', async () => {
        const store = makeStore({ openId: 0 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(store.openId).toBe(5);
    });

    it('sets store.openId to 0 when explicit folderId=0 is passed on clean URL', async () => {
        const store = makeStore({ openId: 5 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL_NO_FOLDER, { folderId: 0 });

        expect(store.openId).toBe(0);
    });

    it('does not throw when Alpine store is unavailable', async () => {
        Alpine.store.mockReturnValue(null);
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await expect(mgr.navigate(UPLOAD_URL)).resolves.toBeUndefined();
    });
});

// ------------------------------------------------------------------
// navigate — fallback
// ------------------------------------------------------------------

describe('navigate — fallback to window.location.assign', () => {
    it('falls back when no adapter matches URL', async () => {
        const mgr = new StaticListNavigationManager();
        await mgr.navigate('http://localhost/wp-admin/options.php');

        expect(console).toHaveErrored();
        expect(window.location.assign).toHaveBeenCalledWith('http://localhost/wp-admin/options.php');
        expect(pushUrl).not.toHaveBeenCalled();
    });

    it('falls back when applyFragments returns false', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        uploadInst.applyFragments.mockReturnValue(false);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(console).toHaveErrored();
        expect(window.location.assign).toHaveBeenCalledWith(UPLOAD_URL);
        expect(pushUrl).not.toHaveBeenCalled();
    });

    it('falls back when fetchListFragments throws a non-abort error', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockRejectedValue(new Error('network error'));

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL);

        expect(console).toHaveErrored();
        expect(window.location.assign).toHaveBeenCalledWith(UPLOAD_URL);
    });

    it('does NOT fall back on AbortError', async () => {
        const abortErr = Object.assign(new Error('aborted'), { name: 'AbortError' });
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockRejectedValue(abortErr);

        const mgr = new StaticListNavigationManager();
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(window.location.assign).not.toHaveBeenCalled();
    });

    it('does not fall back when an older request fails after a newer navigation has started', async () => {
        const first = deferred();
        const second = deferred();
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);

        const mgr = new StaticListNavigationManager();
        const firstRun = mgr.navigate(`${UPLOAD_URL}&paged=1`, { folderId: 5 });
        const secondRun = mgr.navigate(`${UPLOAD_URL}&paged=2`, { folderId: 6 });

        second.resolve(MOCK_DATA);
        await secondRun;

        first.reject(new Error('stale failed'));
        await firstRun;

        expect(window.location.assign).not.toHaveBeenCalled();
        expect(pushUrl).toHaveBeenCalledWith(`${UPLOAD_URL}&paged=2`, { plathixFolderId: 6 });
    });
});

// ------------------------------------------------------------------
// popstate
// ------------------------------------------------------------------

describe('init — popstate', () => {
    it('registers a popstate handler via onPopState', () => {
        const mgr = new StaticListNavigationManager();
        mgr.init();
        expect(onPopState).toHaveBeenCalledTimes(1);
        expect(replaceUrl).toHaveBeenCalledWith(UPLOAD_URL_NO_FOLDER, { plathixFolderId: 0 });
    });

    it('popstate handler calls navigate with push=false when adapter matches', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        const handler = onPopState.mock.calls[0][0];
        await handler(UPLOAD_URL, { state: { plathixFolderId: 5 } });

        expect(mgr.navigate).toHaveBeenCalledWith(UPLOAD_URL, { push: false, folderId: 5, state: { plathixFolderId: 5 } });
    });

    it('popstate handler does nothing when no adapter matches', async () => {
        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        const handler = onPopState.mock.calls[0][0];
        await handler('http://localhost/wp-admin/options.php');

        expect(mgr.navigate).not.toHaveBeenCalled();
    });

    it('back/forward does not push new history entry and syncs openId', async () => {
        const store = makeStore({ openId: 0 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);
        fetchListFragments.mockResolvedValue(MOCK_DATA);

        const mgr = new StaticListNavigationManager();
        mgr.init();

        const handler = onPopState.mock.calls[0][0];
        await handler(UPLOAD_URL, { state: { plathixFolderId: 5 } });

        expect(pushUrl).not.toHaveBeenCalled();
        expect(store.openId).toBe(5);
    });
});

describe('init — folder column links', () => {
    it('intercepts clean folder links and navigates with folderId', () => {
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<a class="plathix-folder-link" data-plathix-folder-id="9" href="http://localhost/wp-admin/upload.php?mode=list">Folder</a>';
        const link = document.querySelector('.plathix-folder-link');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(mgr.navigate).toHaveBeenCalledWith('http://localhost/wp-admin/upload.php?mode=list', { folderId: 9 });
    });

    it('intercepts native "All" view links and resets active folder', () => {
        const store = makeStore({ openId: 9 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<ul class="subsubsub"><li class="all"><a href="http://localhost/wp-admin/edit.php?post_type=page">All</a></li></ul>';
        const link = document.querySelector('.subsubsub a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(store.openId).toBe(0);
        expect(Api.savePreference).toHaveBeenCalledWith('open_folder_id', 0);
        expect(mgr.navigate).toHaveBeenCalledWith('http://localhost/wp-admin/edit.php?post_type=page', { folderId: 0 });
    });

    it('intercepts native "Trash" view links and syncs trash folder', () => {
        const store = makeStore({ openId: 9 });
        Alpine.store.mockReturnValue(store);
        fetchListFragments.mockResolvedValue(MOCK_DATA);
        // CTAN-202: сценарий на upload-канале (Free-адаптер один); trash-перехват generic.
        uploadInst.canHandle.mockImplementation((url) => url.includes('/upload.php'));
        uploadInst.buildParams.mockReturnValue({ screen_base: 'upload', folder_id: 77 });
        uploadInst.applyFragments.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<ul class="subsubsub"><li class="trash"><a href="http://localhost/wp-admin/upload.php?mode=list&status=trash">Trash</a></li></ul>';
        const link = document.querySelector('.subsubsub a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(store.openId).toBe(77);
        expect(Api.savePreference).toHaveBeenCalledWith('open_folder_id', 77);
        expect(mgr.navigate).toHaveBeenCalledWith('http://localhost/wp-admin/upload.php?mode=list&status=trash', { folderId: 77 });
    });
});

describe('init — modifier-key clicks are not intercepted ([internal])', () => {
    it.each([
        ['ctrlKey', { ctrlKey: true }],
        ['metaKey', { metaKey: true }],
        ['shiftKey', { shiftKey: true }],
        ['middle-click', { button: 1 }],
    ])('folder-link: %s click is ignored, navigate not called', (_label, modifiers) => {
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<a class="plathix-folder-link" data-plathix-folder-id="9" href="http://localhost/wp-admin/upload.php?mode=list">Folder</a>';
        const link = document.querySelector('.plathix-folder-link');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...modifiers }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });

    it('native view-switch link: ctrlKey click is ignored, navigate not called', () => {
        const store = makeStore({ openId: 9 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<ul class="subsubsub"><li class="all"><a href="http://localhost/wp-admin/edit.php?post_type=page">All</a></li></ul>';
        const link = document.querySelector('.subsubsub a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });

    it('tablenav pagination link: ctrlKey click is ignored, navigate not called', () => {
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="tablenav"><a class="next-page" href="http://localhost/wp-admin/upload.php?mode=list&paged=2&plathix_folder=9">Next</a></div>';
        const link = document.querySelector('.tablenav a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });

    it('view-switch link: ctrlKey click is ignored, navigate not called', () => {
        const store = makeStore({ openId: 5 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="view-switch"><a class="list" href="http://localhost/wp-admin/upload.php?mode=list">List</a></div>';
        const link = document.querySelector('.view-switch a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });
});

describe('init — view-switch links ([internal])', () => {
    it('intercepts a click on .view-switch and navigates with the CURRENT store folderId when NOT in Trash', () => {
        // Факт со стенда: нативная WP view-switch ссылка теряет плагинные query-параметры
        // (напр. attachment-filter=trash) — href содержит только mode=list. Источник
        // folderId для этого клика — текущее состояние стора (папка 5 открыта в сайдбаре),
        // не парсинг URL клика (там folder-параметра нет вообще). window.location.href
        // (beforeEach) НЕ содержит attachment-filter=trash — обычный (не-Корзина) сценарий.
        const store = makeStore({ openId: 5 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="view-switch"><a class="list" href="http://localhost/wp-admin/upload.php?mode=list">List</a></div>';
        const link = document.querySelector('.view-switch a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(mgr.navigate).toHaveBeenCalledWith('http://localhost/wp-admin/upload.php?mode=list', { folderId: 5 });
    });

    it('REGRESSION [internal] (второй заход): navigates with trashFolderId when the owner snapshot says we are in Trash, even if store.openId disagrees (points to a regular folder)', () => {
        // Баг: store.openId не синхронизируется с trash-состоянием при прямом заходе на
        // upload.php?attachment-filter=trash (см. spec/_done/[internal],
        // [internal] пункт 4) — store хранит последнюю открытую ОБЫЧНУЮ папку (5),
        // а пользователь фактически в Корзине. До фикса (getStoreOpenId() безусловно)
        // navigate() ушёл бы с folderId=5 — ровно симптом со скрина ElenaAloo (список
        // показывает не Корзину).
        //
        // [internal] ([internal]): источник факта "мы в Корзине" теперь
        // isTrashViewActive() (снапшот владельца), не парсинг live URL — мокаем сам
        // снапшот вместо window.location, чтобы тест отражал реальный источник данных
        // после фикса (URL здесь намеренно НЕ содержит attachment-filter=trash — именно
        // это и есть класс бага, который [internal] закрывает: снапшот может быть
        // true, даже когда URL уже не несёт признак Корзины).
        isTrashViewActive.mockReturnValue(true);
        const store = makeStore({ openId: 5 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="view-switch"><a class="list" href="http://localhost/wp-admin/upload.php?mode=list">List</a></div>';
        const link = document.querySelector('.view-switch a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(mgr.navigate).toHaveBeenCalledWith('http://localhost/wp-admin/upload.php?mode=list', { folderId: 77 });
    });

    it('does not intercept a click outside .view-switch', () => {
        const store = makeStore({ openId: 77 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<a class="some-other-link" href="http://localhost/wp-admin/upload.php?mode=list">Not view-switch</a>';
        const link = document.querySelector('.some-other-link');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });

    it('REGRESSION [internal]: does not intercept a click on .view-switch link to mode=grid, letting native browser navigation happen', () => {
        // Живой факт со стенда: UploadListAdapter.canHandle() возвращает true даже для
        // grid-ссылки, пока document.body ещё носит класс mode-list (реальный fallback
        // в canHandle() читает document.body.classList, а не url клика) — здесь мокаем
        // canHandle()=true намеренно, чтобы доказать, что резолвер САМ отсекает
        // mode=grid ДО обращения к адаптеру, независимо от того, что вернул бы канал.
        const store = makeStore({ openId: 5 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="view-switch"><a class="grid" href="http://localhost/wp-admin/upload.php?mode=grid">Grid</a></div>';
        const link = document.querySelector('.view-switch a');
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(event);

        expect(mgr.navigate).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('does not intercept a view-switch link the adapter cannot handle', () => {
        const store = makeStore({ openId: 77 });
        Alpine.store.mockReturnValue(store);
        uploadInst.canHandle.mockReturnValue(false);

        const mgr = new StaticListNavigationManager();
        jest.spyOn(mgr, 'navigate');
        mgr.init();

        document.body.innerHTML = '<div class="view-switch"><a class="list" href="http://localhost/wp-admin/some-other-page.php">List</a></div>';
        const link = document.querySelector('.view-switch a');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(mgr.navigate).not.toHaveBeenCalled();
    });
});

describe('adapter slot — ленивое чтение (ASL-102, [internal])', () => {
    // Контракт: подписчик, зарегистрированный ПОСЛЕ импорта manager.js, участвует в
    // разрешении адаптера. Это и есть отсутствие зависимости от порядка загрузки:
    // PRO-бандлу достаточно встать до первого действия пользователя, а не до module-eval
    // Free-бандла (WP не гарантирует такой порядок — strategy считается по dependents).
    // Мутационный контроль: вернуть `const ADAPTERS = applyFilters(...)` — тест краснеет.
    let registered;

    beforeEach(() => {
        registered = [];
        window.wp = {
            hooks: {
                applyFilters: (name, value, payload) =>
                    registered.reduce((acc, cb) => cb(acc, payload), value),
                addFilter: (name, ns, cb) => registered.push(cb),
                doAction: () => {},
            },
        };
        document.body.innerHTML = '<div id="wpbody"><div id="wpbody-content"></div></div>';
    });

    afterEach(() => {
        delete window.wp;
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('видит адаптер, зарегистрированный ПОСЛЕ импорта модуля', () => {
        // Free-адаптер не берёт эту ссылку — как на реальном PRO-экране.
        uploadInst.canHandle.mockReturnValue(false);

        const lateAdapter = {
            canHandle: jest.fn((url) => url.includes('/edit.php')),
            buildParams: jest.fn(() => ({ screen_base: 'edit', folder_id: 5 })),
            applyFragments: jest.fn(() => true),
        };
        // Регистрация ПОСЛЕ того, как manager.js уже импортирован (см. import выше).
        window.wp.hooks.addFilter('plathix.staticList.adapters', 'test/late', (adapters) => [
            ...adapters,
            lateAdapter,
        ]);

        const mgr = new StaticListNavigationManager();
        mgr.init();
        document.body.innerHTML +=
            '<a class="plathix-folder-link" href="http://localhost/wp-admin/edit.php?post_type=post">L</a>';
        document.querySelector('.plathix-folder-link')
            .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(lateAdapter.canHandle).toHaveBeenCalled();
    });

    it('без подписчиков остаётся только Free-адаптер (upload-канал не задет)', () => {
        uploadInst.canHandle.mockReturnValue(true);

        const mgr = new StaticListNavigationManager();
        mgr.init();

        expect(mgr.getAdapter()).toBe(uploadInst);
    });

    it('[internal]: колбэк фильтра без return не роняет резолвер — деградирует к дефолтному списку', () => {
        uploadInst.canHandle.mockReturnValue(true);

        // Типичная ошибка filter-цепочки (PRO-сторона): колбэк ничего не возвращает,
        // applyFilters() честно пробрасывает undefined дальше (hooks.js:7-15).
        window.wp.hooks.addFilter('plathix.staticList.adapters', 'test/broken', () => {
            // забыли return
        });

        const mgr = new StaticListNavigationManager();
        mgr.init();

        expect(() => mgr.getAdapter()).not.toThrow();
        expect(mgr.getAdapter()).toBe(uploadInst);
    });
});

// ------------------------------------------------------------------
// write-after-invalidate race ([internal])
// ------------------------------------------------------------------

describe('prefetch / navigate — write-after-invalidate race ([internal])', () => {
    it('prefetch() started before an invalidation does not write stale data into the cache', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        const { promise, resolve } = deferred();
        fetchListFragments.mockReturnValue(promise);

        const mgr = new StaticListNavigationManager();
        const prefetchPromise = mgr.prefetch(UPLOAD_URL, { folderId: 5 });

        // мутация инвалидирует папку 5, пока fetch ещё летит
        cacheInvalidateFolder(5);

        resolve(MOCK_DATA);
        await prefetchPromise;

        const params = uploadInst.buildParams.mock.results[0].value;
        expect(cacheGet(params)).toBeNull();
    });

    it('navigate() re-fetches instead of reusing a write that lost the race to an invalidation', async () => {
        uploadInst.canHandle.mockReturnValue(true);
        const { promise: firstFetch, resolve: resolveFirst } = deferred();
        fetchListFragments.mockReturnValueOnce(firstFetch);

        const mgr = new StaticListNavigationManager();
        const navigatePromise = mgr.navigate(UPLOAD_URL, { folderId: 5 });

        // мутация инвалидирует папку 5, пока первый fetch ещё летит
        cacheInvalidateFolder(5);

        resolveFirst(MOCK_DATA);
        await navigatePromise;

        const params = uploadInst.buildParams.mock.results[0].value;
        expect(cacheGet(params)).toBeNull();

        // следующий navigate в ту же папку обязан снова дойти до сети, а не отдать
        // устаревшую запись, пережившую инвалидацию
        fetchListFragments.mockResolvedValueOnce(MOCK_DATA);
        await mgr.navigate(UPLOAD_URL, { folderId: 5 });

        expect(fetchListFragments).toHaveBeenCalledTimes(2);
    });
});
