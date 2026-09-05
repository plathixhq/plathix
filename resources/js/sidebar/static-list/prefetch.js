import { getStaticListManager } from './index.js';

const _isTouch = window.matchMedia?.('(hover: none)').matches ?? false;

let _timer = null;

function onEnter(e) {
    const el = e.target.closest('[data-folder-id]');
    if (!el) return;
    const folderId = Number(el.dataset.folderId);
    clearTimeout(_timer);
    _timer = setTimeout(() => {
        const mgr = getStaticListManager();
        if (!mgr) return;
        const url = mgr.buildUrl(folderId, { resetPage: true });
        if (url) mgr.prefetch(url, { folderId });
    }, 150);
}

function onLeave() {
    clearTimeout(_timer);
}

export function initPrefetch(container) {
    if (_isTouch) return;
    container.addEventListener('mouseenter', onEnter, true);
    container.addEventListener('mouseleave', onLeave, true);
}

export function destroyPrefetch(container) {
    container.removeEventListener('mouseenter', onEnter, true);
    container.removeEventListener('mouseleave', onLeave, true);
    clearTimeout(_timer);
}
