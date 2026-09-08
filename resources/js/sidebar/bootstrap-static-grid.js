import Alpine from 'alpinejs';
import { bootstrapStaticSidebar } from './static-bootstrap.js';
import { installModalMediaPatches } from './modal-bootstrap.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { bindUploadLinkFolderContext } from './upload-link-context.js';
import { infiniteScrollManager } from './infinite-scroll.js';
import { isUploadScreen, isStaticScreen, getFeatures, getRuntime, isTrashViewActive, isTrashViewFromUrl } from './runtime.js';


function applyInitialStaticGridFilter() {
    const store = Alpine.store('plathix');
    const openId = store?.openId;
    if (!store) {
        return;
    }









    //












    const trashId = Number(getRuntime().trashFolderId || 0);
    const target = isTrashViewFromUrl() && trashId > 0
        ? trashId
        : (openId > 0 ? openId : 0);

    let retries = 0;
    const tryApplyGridFilter = () => {
        const frame = window.wp?.media?.frame;
        const content = frame?.content?.get?.();
        const library = frame?.state?.()?.get?.('library');
        if (content?.collection?.props || library?.props) {



            store.applyFolderFilter(target, { resetPage: true });
            return;
        }
        if (++retries < 20) {
            setTimeout(tryApplyGridFilter, 150);
        }
    };
    tryApplyGridFilter();
}



function bindViewSwitchTrashPreservation() {
    document.addEventListener('click', (event) => {
        const linkEl = event.target?.closest?.('a');
        if (!linkEl?.closest?.('.view-switch') || !(linkEl instanceof HTMLAnchorElement)) {
            return;
        }
        const link = linkEl;

        if (!isTrashViewActive()) {
            return;
        }

        try {
            const url = new URL(link.href, window.location.origin);
            if (url.searchParams.get('attachment-filter') === 'trash') {
                return;
            }
            url.searchParams.set('attachment-filter', 'trash');
            link.href = url.toString();
        } catch {

        }
    }, true);
}

function attachInitialInfiniteScrollFrame() {
    if (!isStaticScreen()) {
        return;
    }

    let retries = 0;
    const tryAttachScroll = () => {
        const frame = window.wp?.media?.frame;
        if (frame) {
            infiniteScrollManager.attachFrame(frame);
        } else if (++retries < 20) {
            setTimeout(tryAttachScroll, 150);
        }
    };
    tryAttachScroll();
}

export function bootstrapStaticGrid() {
    bootstrapStaticSidebar();
    installModalMediaPatches();

    const features = getFeatures();
    const canAssign = !!window.Plathix?.caps?.canAssign;
    const canManage = !!window.Plathix?.caps?.canManage;



    if (getRuntime().infiniteScroll) {
        infiniteScrollManager.init();
    }

    if (features.dnd && (canAssign || canManage)) {
        enableAttachmentDnD();


    }

    bindSelectedMediaCountEvents();

    if (features.uploadSync && isUploadScreen()) {
        bindAttachmentDeleteEvents();
        if (canAssign) {
            bindUploadCompleteEvents();
            bindUploadLinkFolderContext();
        }
    }

    applyInitialStaticGridFilter();
    bindViewSwitchTrashPreservation();

    if (getRuntime().infiniteScroll) {
        attachInitialInfiniteScrollFrame();
    }


}
