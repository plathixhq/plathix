import Alpine from 'alpinejs';
import { bootstrapModalSidebar, installModalMediaPatches } from './modal-bootstrap.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { infiniteScrollManager } from './infinite-scroll.js';
import { onMediaFrameReady } from './media-frame-watcher.js';
import { getFeatures, getRuntime } from './runtime.js';

function bindInitialModalFilter() {
    // [internal] ([internal]): ни wp.media.on, ни wp.media.events.on('open', ...)
    // не работают — WP core не даёт глобального события "модалка открылась"
    // (media-frame-watcher.js — единственный владелец этого факта, через DOM).
    onMediaFrameReady(() => {
        // [internal] ([internal]): в стороннем page builder'е (Elementor/Beaver/
        // Bricks/Divi/Oxygen) НЕ применяем persisted "последнюю открытую папку"
        // автоматически — sidebar монтируется и остаётся кликабельным как прежде,
        // но список результатов первого открытия показывает все файлы (root), а не
        // молча отфильтрованную папку из совершенно другого контекста пользователя.
        if (getRuntime().isForeignContext) {
            return;
        }

        const store = Alpine.store('plathix');
        const openId = Number(store?.openId) || 0;
        if (!(openId > 0)) {
            return;
        }

        // [internal] ([internal]): не дублировать retry здесь — applyFolderFilter
        // сам решает, применить фильтр синхронно (props уже есть) или уйти в
        // токенизированный _retryMediaFrameFolderFilter (navigation.js). Два независимых
        // retry-цикла без общего токена были причиной рассинхронизации подсветки/query.
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

    // infiniteScroll — top-level флаг (getRuntime), НЕ features[]: PHP кладёт его top-level,
    // а getFeatures().infiniteScroll давал ложный always-true (undefined!==false).
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
