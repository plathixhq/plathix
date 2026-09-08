


import { resolveMediaFrame } from './runtime.js';
import { setStateValue } from './state.js';

const callbacks = new Set();
let lastKnownFrame = null;
let bodyObserver = null;

function notify(frame) {
    if (!frame || frame === lastKnownFrame) {
        return;
    }
    lastKnownFrame = frame;
    callbacks.forEach((cb) => cb(frame));
}

function watchBody() {
    if (bodyObserver || typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
        return;
    }

    bodyObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof HTMLElement)) continue;
                const frameEl = node.classList.contains('media-frame')
                    ? node
                    : node.querySelector?.('.media-frame');
                if (frameEl) {
                    const frame = resolveMediaFrame();
                    if (frame) {
                        notify(frame);
                    }
                }
            }
        }
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });




    setStateValue('mediaFrameBodyObserver', bodyObserver);
}



export function onMediaFrameReady(callback) {
    callbacks.add(callback);
    watchBody();

    const frame = resolveMediaFrame();
    if (frame) {
        callback(frame);
    }
}
