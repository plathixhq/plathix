import Alpine from 'alpinejs';
import { getMediaFrame } from './runtime.js';
import { onMediaFrameReady } from './media-frame-watcher.js';
import { hasStateFlag, setStateFlag } from './state.js';

let _recountTimer = null;

function updateSelectedMediaCount() {
    const store = Alpine.store('plathix');
    if (!store) return;






    clearTimeout(_recountTimer);
    _recountTimer = setTimeout(() => {
        store.recountFromUi();
    }, 50);
}

export function bindSelectedMediaCountEvents() {
    if (hasStateFlag('selectedCountBound')) return;
    setStateFlag('selectedCountBound');


    document.addEventListener('click', updateSelectedMediaCount, true);
    document.addEventListener('change', updateSelectedMediaCount, true);
    document.addEventListener('keyup', updateSelectedMediaCount, true);


    const bindFrameSelection = (frame) => {
        if (!frame?.on || frame._plathixSelCountBound) return;
        frame._plathixSelCountBound = true;
        const bindState = () => {
            const sel = frame.state?.()?.get?.('selection');
            if (sel?.on && !sel._plathixSelCountBound) {
                sel._plathixSelCountBound = true;
                sel.on('add remove reset', updateSelectedMediaCount);
            }
        };
        bindState();
        frame.on('content:render router:render', bindState);
    };

    const frame = getMediaFrame();
    if (frame) bindFrameSelection(frame);


    onMediaFrameReady(() => bindFrameSelection(getMediaFrame()));
}

export function bindAttachmentDeleteEvents() {
    if (hasStateFlag('attachmentEventsBound')) {
        return;
    }

    const onDeleted = () => {
        Alpine.store('plathix')?.refreshFolders({ silent: true }).catch(() => {});
    };

    const bindToFrame = (frame) => {
        if (!frame?.on || frame._plathixDeleteEventsBound) return;

        frame.on('delete', onDeleted);

        let _destroyTimer = null;
        const onDestroy = () => {
            clearTimeout(_destroyTimer);
            _destroyTimer = setTimeout(onDeleted, 300);
        };

        const bindLibrary = () => {
            const library = frame?.state?.()?.get?.('library');
            if (library?.on && !library._plathixDestroyBound) {
                library.on('destroy', onDestroy);
                library._plathixDestroyBound = true;
            }
        };

        bindLibrary();
        frame.on('content:render', bindLibrary);
        frame.on('router:render', bindLibrary);
        frame._plathixDeleteEventsBound = true;
    };








    let retries = 0;
    const tryBind = () => {
        const frame = getMediaFrame();
        if (frame) {
            bindToFrame(frame);
            return;
        }
        if (++retries < 20) {
            setTimeout(tryBind, 150);
        }
    };
    tryBind();



    onMediaFrameReady(() => {
        const frame = getMediaFrame();
        if (frame) {
            bindToFrame(frame);
        }
    });

    setStateFlag('attachmentEventsBound');
}
