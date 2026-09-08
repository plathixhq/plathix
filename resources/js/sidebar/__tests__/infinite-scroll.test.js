jest.mock('../runtime.js', () => ({
    getRuntime: jest.fn(),
}));

import { getRuntime } from '../runtime.js';
import { InfiniteScrollManager } from '../infinite-scroll.js';

function makeNativeSpinner() {
    const el = document.createElement('span');
    el.className = 'spinner';
    return {
        $el: [el],
        show: jest.fn(() => el.classList.add('is-active')),
        hide: jest.fn(() => el.classList.remove('is-active')),
    };
}

function makeFrame(root, library, { nativeToolbar = false } = {}) {
    const handlers = {};
    const spinner = nativeToolbar ? makeNativeSpinner() : null;
    return {
        el: root,
        on: jest.fn((event, handler) => {
            handlers[event] = handler;
        }),
        off: jest.fn((event, handler) => {
            if (handlers[event] === handler) {
                delete handlers[event];
            }
        }),
        state: () => ({
            get: (key) => (key === 'library' ? library : null),
        }),
        content: {
            get: () => ({
                collection: library,
                toolbar: nativeToolbar ? { get: (key) => (key === 'spinner' ? spinner : null) } : undefined,
            }),
        },
        trigger(event) {
            handlers[event]?.();
        },
        __nativeSpinner: spinner,
    };
}

describe('InfiniteScrollManager', () => {
    beforeEach(() => {
        jest.useFakeTimers();
        jest.clearAllMocks();
        getRuntime.mockReturnValue({ infiniteScroll: true });
        document.body.innerHTML = '';
        window.wp = { media: { frame: null } };
    });

    afterEach(() => {
        jest.runOnlyPendingTimers();
        jest.useRealTimers();
        delete window.wp;
    });

    it('clicks the native load more button when scrolling near the bottom', () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 750, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 300, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 1000, writable: true });

        const button = document.createElement('button');
        button.className = 'load-more';
        button.click = jest.fn();
        Object.defineProperty(button, 'offsetParent', {
            configurable: true,
            get: () => document.body,
        });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        root.appendChild(button);
        document.body.appendChild(root);

        const library = {
            hasMore: jest.fn(() => true),
            more: jest.fn(),
        };

        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);

        jest.advanceTimersByTime(250);
        wrapper.dispatchEvent(new Event('scroll'));

        expect(button.click).toHaveBeenCalledTimes(1);
        expect(library.more).not.toHaveBeenCalled();
    });

    it('triggers load more on WINDOW scroll near bottom (upload.php grid, container does not scroll)', () => {



        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 0, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 5000, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 5000, writable: true });

        const button = document.createElement('button');
        button.className = 'load-more';
        button.click = jest.fn();
        Object.defineProperty(button, 'offsetParent', {
            configurable: true,
            get: () => document.body,
        });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        root.appendChild(button);
        document.body.appendChild(root);

        const library = { hasMore: jest.fn(() => true), more: jest.fn() };
        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);

        jest.advanceTimersByTime(250);


        Object.defineProperty(document.documentElement, 'scrollHeight', { value: 5000, configurable: true });
        window.innerHeight = 800;
        window.scrollY = 4000;
        window.dispatchEvent(new Event('scroll'));

        expect(button.click).toHaveBeenCalledTimes(1);
    });

    it('removes the window scroll listener on detach (no leak)', () => {
        const removeSpy = jest.spyOn(window, 'removeEventListener');
        const root = document.createElement('div');
        document.body.appendChild(root);
        const library = { hasMore: jest.fn(() => true), more: jest.fn() };
        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);
        jest.advanceTimersByTime(250);

        frame.trigger('close');

        expect(removeSpy).toHaveBeenCalledWith('scroll', expect.any(Function));
        removeSpy.mockRestore();
    });

    it('covers public behavior without internal references', () => {
        const root = document.createElement('div');
        document.body.appendChild(root);
        const library = { hasMore: jest.fn(() => true), more: jest.fn() };
        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();

        manager.attachFrame(frame);
        jest.advanceTimersByTime(250);
        expect(root.classList.contains('plathix-infinite-active')).toBe(true);

        frame.trigger('close');
        expect(root.classList.contains('plathix-infinite-active')).toBe(false);
    });

    it('does NOT trigger load more when scroll position is outside the near-bottom threshold (unified formula)', () => {



        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 100, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 300, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 1000, writable: true });

        const button = document.createElement('button');
        button.className = 'load-more';
        button.click = jest.fn();
        Object.defineProperty(button, 'offsetParent', {
            configurable: true,
            get: () => document.body,
        });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        root.appendChild(button);
        document.body.appendChild(root);

        const library = { hasMore: jest.fn(() => true), more: jest.fn() };
        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);

        jest.advanceTimersByTime(250);


        Object.defineProperty(wrapper, 'scrollTop', { value: 50, writable: true });
        wrapper.dispatchEvent(new Event('scroll'));


        expect(button.click).not.toHaveBeenCalled();
    });

    it('shows spinner synchronously on load start and hides it on completion (fallback path)', () => {



        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 750, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 300, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 1000, writable: true });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        document.body.appendChild(root);

        let resolveMore;
        const deferred = { always: jest.fn((cb) => { resolveMore = cb; }) };
        const library = { hasMore: jest.fn(() => true), more: jest.fn(() => deferred) };

        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);
        jest.advanceTimersByTime(250);

        wrapper.dispatchEvent(new Event('scroll'));

        const spinner = root.querySelector('.plathix-infinite-scroll-spinner');
        expect(spinner).not.toBeNull();
        expect(spinner.classList.contains('is-active')).toBe(true);

        resolveMore();
        expect(spinner.classList.contains('is-active')).toBe(false);
    });

    it('uses native wp.media.view.Spinner via frame.content.get().toolbar when available (native path)', () => {





        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 750, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 300, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 1000, writable: true });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        document.body.appendChild(root);

        let resolveMore;
        const deferred = { always: jest.fn((cb) => { resolveMore = cb; }) };
        const library = { hasMore: jest.fn(() => true), more: jest.fn(() => deferred) };

        const frame = makeFrame(root, library, { nativeToolbar: true });
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);
        jest.advanceTimersByTime(250);

        wrapper.dispatchEvent(new Event('scroll'));

        expect(frame.__nativeSpinner.show).toHaveBeenCalledTimes(1);
        expect(root.querySelector('.plathix-infinite-scroll-spinner')).toBeNull();

        resolveMore();
        expect(frame.__nativeSpinner.hide).toHaveBeenCalledTimes(1);
    });

    it('falls back to library.more when there is no visible load more button', () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'attachments-wrapper';
        Object.defineProperty(wrapper, 'scrollTop', { value: 750, writable: true });
        Object.defineProperty(wrapper, 'clientHeight', { value: 300, writable: true });
        Object.defineProperty(wrapper, 'scrollHeight', { value: 1000, writable: true });

        const root = document.createElement('div');
        root.appendChild(wrapper);
        document.body.appendChild(root);

        const deferred = { always: jest.fn((done) => done()) };
        const library = {
            hasMore: jest.fn(() => true),
            more: jest.fn(() => deferred),
        };

        const frame = makeFrame(root, library);
        window.wp.media.frame = frame;
        const manager = new InfiniteScrollManager();
        manager.attachFrame(frame);

        jest.advanceTimersByTime(250);
        wrapper.dispatchEvent(new Event('scroll'));

        expect(library.more).toHaveBeenCalledTimes(1);
    });
});
