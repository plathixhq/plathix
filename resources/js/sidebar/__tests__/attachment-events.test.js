import Alpine from 'alpinejs';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from '../attachment-events.js';
import { getInternalState } from '../state.js';





describe('handles trash workflow consistently', () => {
    let mockFrame;

    beforeEach(() => {
        jest.useFakeTimers();
        Object.keys(getInternalState()).forEach((key) => delete getInternalState()[key]);
        delete window.wp;


        window.Plathix = { screenKind: 'modal' };

        const listeners = {};
        mockFrame = {
            _handlers: listeners,
            on: jest.fn((event, cb) => {
                listeners[event] = listeners[event] || [];
                listeners[event].push(cb);
            }),
            state: jest.fn(() => ({
                get: jest.fn(() => ({
                    on: jest.fn(),
                })),
            })),
        };
    });

    afterEach(() => {
        jest.useRealTimers();
        delete window.wp;
        delete window.Plathix;
    });

    it('covers public behavior without internal references', () => {
        window.wp = { media: {} };

        bindAttachmentDeleteEvents();


        jest.advanceTimersByTime(300);
        expect(mockFrame.on).not.toHaveBeenCalled();



        window.wp.media.frame = mockFrame;
        jest.advanceTimersByTime(150);

        expect(mockFrame.on).toHaveBeenCalledWith('delete', expect.any(Function));
        expect(mockFrame._plathixDeleteEventsBound).toBe(true);
    });

    it('covers public behavior without internal references', () => {
        window.wp = { media: { frame: mockFrame } };
        mockFrame._plathixDeleteEventsBound = true;

        bindAttachmentDeleteEvents();
        jest.advanceTimersByTime(150);

        expect(mockFrame.on).not.toHaveBeenCalled();
    });

    it('covers public behavior without internal references', () => {
        window.wp = { media: {} };

        bindAttachmentDeleteEvents();


        jest.advanceTimersByTime(3000);
        const timerCountAfterExhaustion = jest.getTimerCount();

        jest.advanceTimersByTime(1000);
        expect(jest.getTimerCount()).toBeLessThanOrEqual(timerCountAfterExhaustion);
        expect(mockFrame.on).not.toHaveBeenCalled();
    });
});




describe('covers public behavior without internal references', () => {
    let recountFromUi;

    beforeEach(() => {
        jest.useFakeTimers();
        Object.keys(getInternalState()).forEach((key) => delete getInternalState()[key]);
        delete window.wp;
        window.Plathix = { screenKind: 'static' };

        recountFromUi = jest.fn();
        Alpine.store('plathix', { recountFromUi });
    });

    afterEach(() => {
        jest.useRealTimers();
        delete window.wp;
        delete window.Plathix;
    });

    it('covers public behavior without internal references', () => {
        bindSelectedMediaCountEvents();

        document.dispatchEvent(new Event('click', { bubbles: true }));
        jest.advanceTimersByTime(10);
        document.dispatchEvent(new Event('keyup', { bubbles: true }));
        jest.advanceTimersByTime(10);
        document.dispatchEvent(new Event('change', { bubbles: true }));
        jest.advanceTimersByTime(10);
        document.dispatchEvent(new Event('keyup', { bubbles: true }));


        expect(recountFromUi).not.toHaveBeenCalled();

        jest.advanceTimersByTime(50);

        expect(recountFromUi).toHaveBeenCalledTimes(1);
    });

    it('covers public behavior without internal references', () => {
        bindSelectedMediaCountEvents();

        document.dispatchEvent(new Event('click', { bubbles: true }));
        jest.advanceTimersByTime(50);

        expect(recountFromUi).toHaveBeenCalledTimes(1);
    });
});
