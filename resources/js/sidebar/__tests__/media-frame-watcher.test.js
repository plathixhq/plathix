import { onMediaFrameReady } from '../media-frame-watcher.js';
import { getStateValue } from '../state.js';

describe('covers public behavior without internal references', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        delete window.wp;



    });

    it('calls the callback synchronously when a frame already exists', () => {
        const frame = { id: 'frame-1' };
        window.wp = { media: { frame } };

        const cb = jest.fn();
        onMediaFrameReady(cb);

        expect(cb).toHaveBeenCalledWith(frame);
    });

    it('calls the callback when .media-frame is inserted into the DOM later', async() => {
        jest.resetModules();
        const { onMediaFrameReady: freshOnMediaFrameReady } = await import('../media-frame-watcher.js');

        window.wp = { media: {} };
        const cb = jest.fn();
        freshOnMediaFrameReady(cb);

        expect(cb).not.toHaveBeenCalled();

        const frame = { id: 'frame-async' };
        window.wp.media.frame = frame;

        const frameEl = document.createElement('div');
        frameEl.className = 'media-frame';
        document.body.appendChild(frameEl);

        // MutationObserver callbacks flush as a microtask.
        await new Promise((resolve) => queueMicrotask(resolve));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(cb).toHaveBeenCalledWith(frame);
    });

    it('calls subscribers again on a new frame instance without duplicating for the same frame', async() => {
        jest.resetModules();
        const { onMediaFrameReady: freshOnMediaFrameReady } = await import('../media-frame-watcher.js');

        window.wp = { media: {} };
        const cb = jest.fn();
        freshOnMediaFrameReady(cb);

        const frameA = { id: 'frame-a' };
        window.wp.media.frame = frameA;
        const elA = document.createElement('div');
        elA.className = 'media-frame';
        document.body.appendChild(elA);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(cb).toHaveBeenCalledTimes(1);
        expect(cb).toHaveBeenLastCalledWith(frameA);

        // Same frame re-inserted (e.g. unrelated DOM churn) must not re-trigger.
        document.body.removeChild(elA);
        document.body.appendChild(elA);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(cb).toHaveBeenCalledTimes(1);

        // A genuinely new frame (modal reopened) must trigger again.
        document.body.innerHTML = '';
        const frameB = { id: 'frame-b' };
        window.wp.media.frame = frameB;
        const elB = document.createElement('div');
        elB.className = 'media-frame';
        document.body.appendChild(elB);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(cb).toHaveBeenCalledTimes(2);
        expect(cb).toHaveBeenLastCalledWith(frameB);
    });

    it('covers public behavior without internal references', () => {
        const frame = { id: 'et_file_frame', on: jest.fn() };
        window.wp = { media: { frames: { et_file_frame: frame } } };

        const cb = jest.fn();
        onMediaFrameReady(cb);

        expect(cb).toHaveBeenCalledWith(frame);
    });

    it('does not call the callback when wp.media.frames has no valid Backbone-like entry', () => {
        window.wp = { media: { frames: { junk: { notAFrame: true } } } };

        const cb = jest.fn();
        onMediaFrameReady(cb);

        expect(cb).not.toHaveBeenCalled();
    });

    it('covers public behavior without internal references', async() => {
        jest.resetModules();
        const { onMediaFrameReady: freshOnMediaFrameReady } = await import('../media-frame-watcher.js');
        const { getStateValue: freshGetStateValue } = await import('../state.js');

        window.wp = { media: {} };
        const cb = jest.fn();
        freshOnMediaFrameReady(cb);

        const frameA = { id: 'frame-a' };
        window.wp.media.frame = frameA;
        const elA = document.createElement('div');
        elA.className = 'media-frame';
        document.body.appendChild(elA);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(cb).toHaveBeenCalledTimes(1);

        const observer = freshGetStateValue('mediaFrameBodyObserver');
        expect(observer).toBeInstanceOf(MutationObserver);
        observer.disconnect();

        document.body.innerHTML = '';
        const frameB = { id: 'frame-b' };
        window.wp.media.frame = frameB;
        const elB = document.createElement('div');
        elB.className = 'media-frame';
        document.body.appendChild(elB);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(cb).toHaveBeenCalledTimes(1);
    });
});
