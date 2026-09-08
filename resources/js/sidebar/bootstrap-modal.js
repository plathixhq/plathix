import Alpine from 'alpinejs';
import { bootstrapModalSidebar, installModalMediaPatches } from './modal-bootstrap.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { infiniteScrollManager } from './infinite-scroll.js';
import { onMediaFrameReady } from './media-frame-watcher.js';
import { getFeatures, getRuntime } from './runtime.js';

function bindInitialModalFilter() {



    onMediaFrameReady(() => {





        if (getRuntime().isForeignContext) {
            return;
        }

        const store = Alpine.store('plathix');
        const openId = Number(store?.openId) || 0;
        if (!(openId > 0)) {
            return;
        }





        store.applyFolderFilter(openId);
    });
}

export function bootstrapModal() {
    bootstrapModalSidebar();
    installModalMediaPatches();
    bindInitialModalFilter();

    const features = getFeatures();
    const canAssign = !!window.Plathix?.caps?.canAssign;
    const canManage = !!window.Plathix?.caps?.canManage;



    if (getRuntime().infiniteScroll) {
        infiniteScrollManager.init();
    }

    if (features.dnd && (canAssign || canManage)) {
        enableAttachmentDnD();
        // bindFolderDropzone() is intentionally omitted — dropzone targets the
        // upload list page DOM which does not exist in the modal context.
    }

    bindSelectedMediaCountEvents();

    if (features.uploadSync) {
        bindAttachmentDeleteEvents();
        if (canAssign) {
            bindUploadCompleteEvents();
        }
    }
}
