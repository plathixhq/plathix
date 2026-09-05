import Alpine from 'alpinejs';
import { bindAttachmentDeleteEvents } from '../attachment-events.js';
import { getInternalState } from '../state.js';

// [internal]: getMediaFrame() может вернуть null на первой попытке (WP core grid-frame
// ещё не создан к моменту загрузки Plathix-бандла) — bindAttachmentDeleteEvents должен
// повторять попытку, а не полагаться только на глобальное событие wp.media 'open' (которое
// для grid-frame никогда не эмитится, т.к. WP core создаёт его напрямую, не через .open()).
describe('bindAttachmentDeleteEvents — retry при отложенно доступном frame ([internal])', () => {
    let mockFrame;

    beforeEach(() => {
        jest.useFakeTimers();
        Object.keys(getInternalState()).forEach((key) => delete getInternalState()[key]);
        delete window.wp;
        // screenKind: 'modal' — простейший путь getFilterStrategy() → 'media-frame',
        // чтобы getMediaFrame() реально читал window.wp.media.frame (не всегда null).
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

    it('подписывается на library.destroy не сразу, а через несколько retry, когда frame появляется с задержкой', () => {
        window.wp = { media: {} };

        bindAttachmentDeleteEvents();

        // Первые несколько тиков — frame ещё не существует, подписки быть не должно.
        jest.advanceTimersByTime(300);
        expect(mockFrame.on).not.toHaveBeenCalled();

        // Frame "появляется" — имитирует WP core grid создающий wp.media.frame асинхронно
        // после Plathix-бандла (race condition, воспроизведённая на живом стенде).
        window.wp.media.frame = mockFrame;
        jest.advanceTimersByTime(150);

        expect(mockFrame.on).toHaveBeenCalledWith('delete', expect.any(Function));
        expect(mockFrame._plathixDeleteEventsBound).toBe(true);
    });

    it('не подписывается повторно, если frame уже привязан (идемпотентность)', () => {
        window.wp = { media: { frame: mockFrame } };
        mockFrame._plathixDeleteEventsBound = true;

        bindAttachmentDeleteEvents();
        jest.advanceTimersByTime(150);

        expect(mockFrame.on).not.toHaveBeenCalled();
    });

    it('останавливает retry после исчерпания попыток, если frame так и не появился', () => {
        window.wp = { media: {} };

        bindAttachmentDeleteEvents();

        // 20 попыток по 150ms = 3000ms; после этого больше не должно быть setTimeout-вызовов.
        jest.advanceTimersByTime(3000);
        const timerCountAfterExhaustion = jest.getTimerCount();

        jest.advanceTimersByTime(1000);
        expect(jest.getTimerCount()).toBeLessThanOrEqual(timerCountAfterExhaustion);
        expect(mockFrame.on).not.toHaveBeenCalled();
    });
});
