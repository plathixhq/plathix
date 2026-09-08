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

    describe('covers public behavior without internal references', () => {
        it('covers public behavior without internal references', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });

        it('covers public behavior without internal references', () => {
            getRuntime.mockReturnValue({ mediaModalOnly: false, skinClasses: [] });

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).not.toHaveBeenCalled();
        });
    });

    describe('covers public behavior without internal references', () => {
        it('covers public behavior without internal references', async () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);


            wpFrame.trigger('content:render');


            expect(Alpine.initTree).toHaveBeenCalledTimes(1);



            const oldMenu = frameEl.querySelector('.media-frame-menu');
            const newMenu = document.createElement('div');
            newMenu.className = 'media-frame-menu';
            const newInner = document.createElement('ul');
            newInner.className = 'media-menu';
            newMenu.appendChild(newInner);
            frameEl.replaceChild(newMenu, oldMenu);


            Alpine.initTree.mockClear();
            wpFrame.trigger('content:render');

            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
        });
    });

    describe('covers public behavior without internal references', () => {
        it('preserves folder tree behavior', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            Alpine.initTree.mockClear();


            wpFrame.trigger('content:render');
            wpFrame.trigger('router:render');
            wpFrame.trigger('content:render');


            expect(Alpine.initTree).not.toHaveBeenCalled();
        });
    });

    describe('covers public behavior without internal references', () => {
        it('covers public behavior without internal references', () => {
            const frameEl = document.createElement('div');
            frameEl.className = 'media-frame';


            const content = document.createElement('div');
            content.className = 'media-frame-content';
            frameEl.appendChild(content);

            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();



            jest.useFakeTimers();
            manager.mount();


            for (let i = 0; i < 6; i++) {
                jest.runAllTimers();
            }

            jest.useRealTimers();

            expect(frameEl.querySelector('.media-frame-menu')).not.toBeNull();
            expect(Alpine.initTree).toHaveBeenCalledTimes(1);
            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });
    });

    describe('covers public behavior without internal references', () => {
        it('covers public behavior without internal references', () => {


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

        it('covers public behavior without internal references', () => {




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

    describe('prevents concurrent state changes', () => {
        it('covers public behavior without internal references', () => {
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

        it('covers public behavior without internal references', () => {
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

    describe('covers public behavior without internal references', () => {
        it('covers public behavior without internal references', () => {





            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();


            wpFrame.trigger('close');
            expect(frameEl.querySelector('#plathix-modal-root')).toBeNull();



            wpFrame.trigger('open');

            expect(frameEl.querySelector('#plathix-modal-root')).not.toBeNull();
        });

        it('covers public behavior without internal references', () => {
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




            openReadyCallback(wpFrame);

            const openHandlerCountAfterSecond = wpFrame.on.mock.calls.filter(([event]) => event === 'open').length;
            expect(openHandlerCountAfterSecond).toBe(1);
        });

        it('covers public behavior without internal references', () => {
            const frameEl = buildMediaFrame();
            document.body.appendChild(frameEl);
            const wpFrame = buildWpMediaFrame(frameEl);

            onMediaFrameReady.mockImplementation((cb) => cb(wpFrame));

            const manager = new MountManager();
            manager.mount();

            wpFrame.trigger('close');




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

    it('covers public behavior without internal references', () => {
        getRuntime.mockReturnValue({ screenKind: 'static', mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).not.toBeNull();
    });

    it('covers public behavior without internal references', () => {

        getRuntime.mockReturnValue({
            screenKind: 'static',
            screenBase: 'edit',
            mediaModalOnly: false,
            skinClasses: [],
        });

        expect(ensureStaticRoot()).not.toBeNull();
    });

    it('covers public behavior without internal references', () => {
        getRuntime.mockReturnValue({ screenKind: 'modal', mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });

    it('covers public behavior without internal references', () => {


        getRuntime.mockReturnValue({ mediaModalOnly: false, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });

    it('covers public behavior without internal references', () => {
        getRuntime.mockReturnValue({ screenKind: 'static', mediaModalOnly: true, skinClasses: [] });

        expect(ensureStaticRoot()).toBeNull();
    });
});
