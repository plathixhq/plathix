import Alpine from 'alpinejs';
import { bootstrapStaticSidebar } from './static-bootstrap.js';
import { initStaticListNavigation } from './static-list/index.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { bindUploadLinkFolderContext } from './upload-link-context.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { isUploadScreen, getFeatures } from './runtime.js';


export function bootstrapStaticList() {
    bootstrapStaticSidebar();
    initStaticListNavigation();



    const features = getFeatures();
    const canAssign = !!window.Plathix?.caps?.canAssign;
    const canManage = !!window.Plathix?.caps?.canManage;

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
}
