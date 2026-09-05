import Alpine from 'alpinejs';
import { bootstrapStaticSidebar } from './static-bootstrap.js';
import { initStaticListNavigation } from './static-list/index.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { bindUploadLinkFolderContext } from './upload-link-context.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { isUploadScreen, getFeatures } from './runtime.js';
// initFolderTrashPanelList уехал в trash-entry.js ([internal]).

export function bootstrapStaticList() {
    bootstrapStaticSidebar();
    initStaticListNavigation();

    // Панель корзины папок монтируется trash-entry.js ([internal]).

    const features = getFeatures();
    const canAssign = !!window.Plathix?.caps?.canAssign;
    const canManage = !!window.Plathix?.caps?.canManage;

    if (features.dnd && (canAssign || canManage)) {
        enableAttachmentDnD();
        // bindFolderDropzone() уехал в PRO ([internal]): dropzone загрузки папок —
        // часть PRO-фичи FolderUpload, PRO байндит его на plathix:ready. Без PRO dropzone нет.
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
