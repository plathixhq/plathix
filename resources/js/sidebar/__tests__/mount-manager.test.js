jest.mock('../runtime.js', () => ({
    getRuntime: jest.fn(() => ({ mediaModalOnly: true, skinClasses: [] })),
    getPostType: jest.fn(() => 'attachment'),
}));

jest.mock('../resize.js', () => ({
    SidebarResizer: { loadState: jest.fn(() => ({ collapsed: false, width: 0 })) },
}));

jest.mock('../templates/sidebar.js', () => ({
    sidebarMarkup: jest.fn(() => '<div class="plathix-sidebar"></div>'),
}));

jest.mock('../dom.js', () => ({
    STATIC_ROOT_ID: 'plathix-sidebar-root',
    MODAL_ROOT_ID: 'plathix-modal-root',
    getStaticSidebarRoot: jest.fn(() => null),
}));

jest.mock('alpinejs', () => ({
    initTree: jest.fn((el) => {
        // Имитирует реальное Alpine: после initTree элемент получает _x_dataStack.
        // MountManager использует root._x_dataStack как guard против повторного mount.
        el._x_dataStack = [{}];
    }),
    store: jest.fn(() => ({ resetTransientState: jest.fn(), cleanup: jest.fn() })),
}));

jest.mock('../media-frame-watcher.js', () => ({
    onMediaFrameReady: jest.fn(),
}));

import Alpine from 'alpinejs';
import { MountManager, ensureStaticRoot } from '../mount-manager.js';
import { getRuntime } from '../runtime.js';
import { onMediaFrameReady } from '../media-frame-watcher.js';

function buildMediaFrame() {
    const frame = document.createElement('div');
    frame.className = 'media-frame';

    const menu = document.createElement('div');
    menu.className = 'media-frame-menu';

    const menuInner = document.createElement('ul');
    menuInner.className = 'media-menu';
    menu.appendChild(menuInner);
    frame.appendChild(menu);

    const content = document.createElement('div');
    content.className = 'media-frame-content';
    frame.appendChild(content);

    return frame;
}

function buildWpMediaFrame(frameEl) {
    const listeners = {};
    return {
        el: frameEl,
        on: jest.fn((event, handler) => {
            if (!listeners[event]) listeners[event] = [];
            listeners[event].push(handler);
        }),
        off: jest.fn((event, handler) => {
            if (listeners[event]) {
                listeners[event] = listeners[event].filter((h) => h !== handler);
            }
        }),
        trigger: (event) => (listeners[event] || []).forEach((h) => h()),
    };
}

describe('MountManager', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        document.body.innerHTML = '';
        getRuntime.mockReturnValue({ mediaModalOnly: true, skinClasses: [] });
    });

    describe('первичный mount', () => {
        it('монтирует sidebar в .media-menu при открытии фрейма', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });

        it('не монтирует если mediaModalOnly=false', () => {
            getRuntime.mockReturnValue({ mediaModalOnly: false, skinClasses: [] });

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).not.toHaveBeenCalled();
        });
    });

    describe('remount после удаления родительского контейнера', () => {
        it('перемонтирует sidebar если root исчез вместе с предком', async () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);

            // Симулируем content:render (builder перерисовал содержимое фрейма)
            wpFrame.trigger('content:render');

            // initTree не должен вызваться повторно если root ещё в DOM
            expect(Alpine.initTree).toHaveBeenCalledTimes(1);

            // Симулируем реальный сценарий: builder заменяет .media-frame-menu
            // целиком — root исчезает как побочный эффект замены предка.
            const oldMenu = frameEl.querySelector('.media-frame-menu');
            const newMenu = document.createElement('div');
            newMenu.className = 'media-frame-menu';
            const newInner = document.createElement('ul');
            newInner.className = 'media-menu';
            newMenu.appendChild(newInner);
            frameEl.replaceChild(newMenu, oldMenu);

            // После content:render с отсутствующим root — должен перемонтироваться
            Alpine.initTree.mockClear();
            wpFrame.trigger('content:render');

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
        });
    });

    describe('смена router/content в modal подряд', () => {
        it('не дублирует Alpine.initTree при множественных content:render', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            Alpine.initTree.mockClear();

            // Несколько content:render подряд — root уже в DOM
            wpFrame.trigger('content:render');
            wpFrame.trigger('router:render');
            wpFrame.trigger('content:render');

            // initTree не должен вызываться повторно — root уже замаунчен
            expect(Alpine.initTree).not.toHaveBeenCalled();
        });
    });

    describe('builder-контекст без .media-frame-menu', () => {
        it('создаёт synthetic .media-frame-menu и монтирует sidebar', () => {
            const frameEl = document.createElement('div');
            frameEl.className = 'media-frame';

            // Добавляем content чтобы фрейм считался готовым
            const content = document.createElement('div');
            content.className = 'media-frame-content';
            frameEl.appendChild(content);

            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();

            // Нужно исчерпать retry-счётчик (5 попыток) чтобы создался synthetic menu
            // Делаем это через прямые вызовы — симулируем 5+ ensureMounted попыток
            jest.useFakeTimers();
            manager.mount();

            // Прогоняем таймеры 6 раз чтобы пройти порог mountRetryCount < 5
            for (let i = 0; i < 6; i++) {
                jest.runAllTimers();
            }

            jest.useRealTimers();

            expect(frameEl.querySelector('.media-frame-menu')).not.toBeNull();
            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });
    });

    describe('retry cap ([internal])', () => {
        it('прекращает retry после исчерпания cap, если .media-frame так и не появился', () => {
            // Нет .media-frame в DOM вообще — ветка !mediaFrame раньше крутила
            // #queueEnsureMounted() бесконечно каждые 50мс.
            onMediaFrameReady.mockImplementation(() => {});

            const manager = new MountManager();
            jest.useFakeTimers();
            manager.mount();

            for (let i = 0; i < 10; i++) {
                jest.advanceTimersByTime(50);
            }

            expect(jest.getTimerCount()).toBe(0);
            expect(Alpine.initTree).not.toHaveBeenCalled();

            jest.useRealTimers();
        });

        it('прекращает retry после исчерпания cap, если .media-menu так и не появился внутри menuPanel', () => {
            // Реальный (не synthetic) .media-frame-menu без .media-menu внутри и без
            // .media-frame-content — не идёт по synthetic-пути (frameIsReady=false
            // держит первую ветку), а .media-menu внутри существующего menuPanel так и
            // не появляется.
            const frameEl = document.createElement('div');
            frameEl.className = 'media-frame';
            const menuPanel = document.createElement('div');
            menuPanel.className = 'media-frame-menu';
            frameEl.appendChild(menuPanel);
            const content = document.createElement('div');
            content.className = 'media-frame-content';
            frameEl.appendChild(content);
            document.body.appendChild(frameEl);

            const wpFrame = buildWpMediaFrame(frameEl);
            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            jest.useFakeTimers();
            manager.mount();

            for (let i = 0; i < 10; i++) {
                jest.advanceTimersByTime(50);
            }

            expect(jest.getTimerCount()).toBe(0);
            expect(Alpine.initTree).not.toHaveBeenCalled();

            jest.useRealTimers();
        });
    });

    describe('graceful degradation при закрытии modal', () => {
        it('вызывает cleanup store при закрытии фрейма', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);
            const mockStore = { resetTransientState: jest.fn(), cleanup: jest.fn() };
            Alpine.store.mockReturnValue(mockStore);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            wpFrame.trigger('close');

            expect(mockStore.cleanup).toHaveBeenCalledTimes(1);
        });

        it('удаляет modal root при закрытии фрейма', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();

            wpFrame.trigger('close');

            expect(frameEl.querySelector('#plathix-modal-root')).toBeNull();
        });
    });

    describe('[internal]: переиспользуемый frame instance (Elementor Control_Media)', () => {
        it('перемонтирует sidebar на повторный "open" ТОГО ЖЕ frame instance после close', () => {
            // Воспроизводит Control_Media.openFrame(): "if there is no frame ... (re)initialize" —
            // при повторном клике Elementor вызывает .open() на уже существующем frame,
            // без нового wp.media() и без нового DOM-узла .media-frame — MutationObserver-детектор
            // (media-frame-watcher.js) НЕ сработает повторно, единственный сигнал — само событие
            // 'open', которое WP core триггерит при каждом показе (wp.media.view.Modal#open()).
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();

            // Закрытие — teardownMountedFrame удаляет root (существующее поведение).
            wpFrame.trigger('close');
            expect(frameEl.querySelector('#plathix-modal-root')).toBeNull();

            // media-frame-watcher.js НЕ вызывает onMediaFrameReady/#onOpen повторно (тот же
            // DOM-узел, нет addedNodes) — единственный путь восстановления это сам 'open'.
            wpFrame.trigger('open');

            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });

        it('не навешивает второй "open"-listener на тот же frame instance при повторном #onOpen (нет утечки)', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            let openReadyCallback;
            onMediaFrameReady.mockImplementation((cb) => {
                openReadyCallback = cb;
                cb(wpFrame);
            });

            const manager = new MountManager();
            manager.mount();

            const openHandlerCountAfterFirst = wpFrame.on.mock.calls.filter(([event]) => event === 'open').length;
            expect(openHandlerCountAfterFirst).toBe(1);

            // Симулируем, что MutationObserver всё же сработал повторно на том же frame
            // (например, редкий edge case первого открытия с задержкой) — #onOpen() вызывается
            // снова с ТЕМ ЖЕ wpFrame instance.
            openReadyCallback(wpFrame);

            const openHandlerCountAfterSecond = wpFrame.on.mock.calls.filter(([event]) => event === 'open').length;
            expect(openHandlerCountAfterSecond).toBe(1);
        });

        it('"open"-listener переживает #teardownFrameListeners() (не снимается при close)', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            wpFrame.trigger('close');

            // off() не должен был быть вызван с 'open' — иначе триггер 'open' на переиспользуемом
            // instance молча ничего не сделает (это и был найденный skeptic'ом дефект первой
            // версии фикса: биндинг внутри #bindFrameEvents()/#listeners снимался в onClose).
            const offCalledWithOpen = wpFrame.off.mock.calls.some(([event]) => event === 'open');
            expect(offCalledWithOpen).toBe(false);
        });
    });
});

describe('ensureStaticRoot — screen gate (CEC-101)', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="wpbody"><div id="wpbody-content"></div></div>';
    });

    afterEach(() => {
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('монтирует, когда PHP-резолвер отдал screenKind=static (медиатека Free)', () => {
        getRuntime.mockReturnValue({ screenKind: 'static', mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).not.toBeNull();
    });

    it('монтирует на PRO-экране: тот же screenKind=static из PRO-конфига, без списка имён экранов', () => {
        // PRO подаёт свой ctx через слот plathix/assets/js_data; JS не знает слова 'edit'.
        getRuntime.mockReturnValue({
            screenKind: 'static',
            screenBase: 'edit',
            mediaModalOnly: false,
            skinClasses: [],
        });

        expect(ensureStaticRoot()).not.toBeNull();
    });

    it('НЕ монтирует на модальном экране', () => {
        getRuntime.mockReturnValue({ screenKind: 'modal', mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });

    it('НЕ монтирует при неотданном конфиге (screenKind отсутствует) — ловушка дефолта getScreenKind()', () => {
        // Регресс-контроль: чтение через getScreenKind() дефолтит в 'static' и смонтировало бы
        // сайдбар там, где его быть не должно. Гейт обязан читать поле напрямую.
        getRuntime.mockReturnValue({ mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });

    it('НЕ монтирует при mediaModalOnly, даже если screenKind=static', () => {
        getRuntime.mockReturnValue({ screenKind: 'static', mediaModalOnly: true, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });
});
