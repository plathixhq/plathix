import Alpine from 'alpinejs';
import { doAction } from './hooks.js';
import { sidebarStore } from './store.js';
import { folderTree } from './components/FolderTree.js';
import { contextMenuComponent } from './components/ContextMenu.js';
import { bulkActionsComponent } from './components/BulkActions.js';
import { t } from './i18n.js';
import { getPostType, shouldUseMediaFrameFiltering, shouldUseStaticListFiltering, isStaticScreen, getFilterStrategy, getScreenKind, isTrashViewActive } from './runtime.js';
import { hasStateFlag, setStateFlag } from './state.js';
import { memClear } from './media-grid-cache.js';
import { installPublicApi } from './public-api.js';
import { bootstrapStaticList } from './bootstrap-static-list.js';
import { bootstrapStaticGrid } from './bootstrap-static-grid.js';
import { bootstrapModal } from './bootstrap-modal.js';
import { stripInitialFolderParamForMediaFrame } from './url-utils.js';
import { guardTrashUrl, bindViewSwitchTrashHrefGuard } from './static-list/history.js';
import '../../css/sidebar.css';

export const PLATHIX_STORE_KEY = 'plathix';

function registerAlpineBindings() {
    document.addEventListener('alpine:init', () => {
        Alpine.store(PLATHIX_STORE_KEY, sidebarStore);
        Alpine.data('folderTree', folderTree);
        Alpine.data('contextMenu', contextMenuComponent);


        Alpine.data('bulkActions', bulkActionsComponent);




    }, { once: true });
}

function bindBeforeUnloadPersistence() {
    if (hasStateFlag('beforeUnloadBound')) {
        return;
    }

    window.addEventListener('beforeunload', (event) => {
        const store = Alpine.store('plathix');
        if (store?.isUploading) {
            event.preventDefault();
            event.returnValue = t('upload_reload_warning', 'Uploads are still in progress. Leaving now may interrupt them.');
        }
    });
    setStateFlag('beforeUnloadBound');
}

async function plathixInit() {
    if (typeof window.Plathix === 'undefined') {
        return;
    }

    installPublicApi();

    if (hasStateFlag('bootstrapped')) {
        return;
    }

    setStateFlag('bootstrapped');

    window.Plathix.isTouch = window.matchMedia?.('(pointer: coarse)')?.matches ?? false;



    window.Plathix.mediaGridClear = memClear;
    registerAlpineBindings();

    if (shouldUseMediaFrameFiltering()) {
        stripInitialFolderParamForMediaFrame();
    }

    window.Alpine = Alpine;
    Alpine.start();






    guardTrashUrl(isTrashViewActive);





    bindViewSwitchTrashHrefGuard(isTrashViewActive);

    if (shouldUseStaticListFiltering()) {
        bootstrapStaticList();
    } else if (shouldUseMediaFrameFiltering() && isStaticScreen()) {
        bootstrapStaticGrid();
    } else if (shouldUseMediaFrameFiltering()) {
        bootstrapModal();
    } else {












        doAction('plathix.sidebarBootstrapFallback', { filterStrategy: getFilterStrategy(), screenKind: getScreenKind() });
        if (window.Plathix?.debug) {
            console.warn(
                `Plathix sidebar: no bootstrap path matched (filterStrategy=${getFilterStrategy()}, screenKind=${getScreenKind()}). DnD/upload-sync/infinite-scroll will not be active.`
            );
        }
    }

    bindBeforeUnloadPersistence();






    //








    doAction('plathix.sidebarReady', { postType: getPostType(), screenKind: window.Plathix?.screenKind ?? 'static' });
    window.__PlathixApiReady = true;
    window.dispatchEvent(new CustomEvent('plathix:ready', {
        detail: { postType: getPostType(), screenKind: window.Plathix?.screenKind ?? 'static' },
    }));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', plathixInit);
} else {
    plathixInit();
}
