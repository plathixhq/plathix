import Alpine from 'alpinejs';
import { getMediaFrame } from './runtime.js';
import { onMediaFrameReady } from './media-frame-watcher.js';
import { hasStateFlag, setStateFlag } from './state.js';

function updateSelectedMediaCount() {
    const store = Alpine.store('plathix');
    if (!store) return;
    // setTimeout даёт WP успеть обновить DOM перед чтением.
    // [internal]: пересчёт из UI (Math.max DOM+frame) теперь у владельца
    // выбора (store/selection.js recountFromUi) — здесь только триггер по интерактивным событиям.
    setTimeout(() => {
        store.recountFromUi();
    }, 50);
}

export function bindSelectedMediaCountEvents() {
    if (hasStateFlag('selectedCountBound')) return;
    setStateFlag('selectedCountBound');

    // Клики по grid и чекбоксам — самый надёжный триггер
    document.addEventListener('click', updateSelectedMediaCount, true);
    document.addEventListener('change', updateSelectedMediaCount, true);
    document.addEventListener('keyup', updateSelectedMediaCount, true);

    // wp.media frame: события selection
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
    // [internal] ([internal]): wp.media.on/wp.media.events.on('open') не работают —
    // media-frame-watcher.js единственный владелец обнаружения frame через DOM.
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

    // [internal]: на grid-экране (upload.php?mode=grid) WP core создаёт frame напрямую
    // (wp.media.frames.browse), не через wp.media().open() — глобальное событие 'open'
    // никогда не эмитится для него, поэтому единственный fallback ниже бесполезен именно
    // там. Немедленный getMediaFrame() тоже не гарантирован: порядок загрузки скриптов
    // между WP core grid-bootstrap и этим модулем браузером не гарантирован. Retry —
    // тот же паттерн, что уже используют applyInitialStaticGridFilter/
    // attachInitialInfiniteScrollFrame в bootstrap-static-grid.js для той же race condition.
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

    // [internal] ([internal]): wp.media.on/wp.media.events.on('open') не работают —
    // media-frame-watcher.js единственный владелец обнаружения frame через DOM.
    onMediaFrameReady(() => {
        const frame = getMediaFrame();
        if (frame) {
            bindToFrame(frame);
        }
    });

    setStateFlag('attachmentEventsBound');
}
