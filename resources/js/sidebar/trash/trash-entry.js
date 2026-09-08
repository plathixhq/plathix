

import './trash.css';
import { initFolderTrashPanel } from './trash-panel.js';
import { initFolderTrashPanelList } from './trash-panel-list.js';
import { getCachedTrashedFolderIds, refreshTrashedFolderIds, onTrashedFolderIdsChange } from './trash-core.js';
import { Events } from '../events.js';
import { trashActionsHTML, ACTION_MARKER } from './trash-toolbar-actions.js';
import { trashOverlaysHTML, OVERLAY_MARKER } from './trash-overlays.js';
import { syncMediaToolbarTrashClass } from './trash-core-toolbar-suppress.js';
import { setStateValue } from '../state.js';

const ACTION_SLOT = 'plathix-trash-actions';
const OVERLAY_SLOT = 'plathix-trash-overlay';

function fillSlot(slot, html, marker, A) {
    if (slot.querySelector('.' + marker)) {
        return;
    }
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    const node = tmp.firstElementChild;
    slot.appendChild(node);


    if (typeof A.initTree === 'function') {
        A.initTree(node);
    }
}

function fillAllSlots(A) {
    document.querySelectorAll('[data-slot="' + ACTION_SLOT + '"]').forEach((slot) => {
        fillSlot(slot, trashActionsHTML(), ACTION_MARKER, A);
    });
    document.querySelectorAll('[data-slot="' + OVERLAY_SLOT + '"]').forEach((slot) => {
        fillSlot(slot, trashOverlaysHTML(), OVERLAY_MARKER, A);
    });
}

function initPanels(A) {
    const store = A.store('plathix');
    if (!store) {
        return;
    }
    const canManage = !!(window.Plathix?.caps?.canManage);
    if (canManage) {
        initFolderTrashPanel(store);
    }
    initFolderTrashPanelList(store);
}



function initTrashToolbarImpl(store) {
    store._trashImpl = {
        isCurrentFolderTrashed() {
            const ids = getCachedTrashedFolderIds();
            return ids.has(Number(store.openId));
        },
    };






    onTrashedFolderIdsChange(() => { store._trashedFolderIdsVersion++; });

    const refresh = () => {
        refreshTrashedFolderIds();
        syncMediaToolbarTrashClass(store);
    };
    window.wp?.hooks?.addAction?.('plathix.folderOpened', 'plathix/trash-toolbar', refresh);
    window.addEventListener(Events.FOLDER_DELETED, refresh);
    refresh();
}

function onPlathixReady() {
    const A = window.Alpine;
    if (!A) {
        return;
    }
    fillAllSlots(A);
    initPanels(A);
    const store = A.store('plathix');
    if (store) {
        initTrashToolbarImpl(store);
    }









    const mo = new MutationObserver(() => {
        fillAllSlots(A);
        if (store) {
            syncMediaToolbarTrashClass(store);
        }
    });
    mo.observe(document.body, { childList: true, subtree: true });


    setStateValue('trashEntryBodyObserver', mo);
}

if (window.__PlathixApiReady) {
    onPlathixReady();
} else {
    window.addEventListener('plathix:ready', onPlathixReady, { once: true });
}
