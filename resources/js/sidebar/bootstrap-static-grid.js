import Alpine from 'alpinejs';
import { bootstrapStaticSidebar } from './static-bootstrap.js';
import { installModalMediaPatches } from './modal-bootstrap.js';
import { enableAttachmentDnD } from './dnd.js';
import { bindAttachmentDeleteEvents, bindSelectedMediaCountEvents } from './attachment-events.js';
import { bindUploadCompleteEvents } from './upload-events.js';
import { bindUploadLinkFolderContext } from './upload-link-context.js';
import { infiniteScrollManager } from './infinite-scroll.js';
import { isUploadScreen, isStaticScreen, getFeatures, getRuntime, isTrashViewActive } from './runtime.js';
// initFolderTrashPanel уехал в trash-entry.js ([internal]).

function applyInitialStaticGridFilter() {
    const store = Alpine.store('plathix');
    const openId = store?.openId;
    if (!store) {
        return;
    }

    // Первый запуск: нет сохранённого выбора папки → openId=0 (Preferences отдаёт 0 при
    // пустом user_meta). Раньше здесь был ранний return, из-за чего на чистой установке
    // грид WP-медиатеки не синхронизировался со стором и оставался пустым, а системная
    // папка «Медиафайлы» не вставала активной без ручного клика. openId=0 — это ЛЕГИТИМНОЕ
    // системное представление «Все файлы» (инвариант [internal]:
    // openId===0 → «Медиафайлы» is-open). Поэтому трактуем openId<=0 как явный «показать все»
    // и всё равно инициируем фильтр — applyFolderFilter(0) снимает plathix_folder, и сервер
    // отдаёт весь набор. Подсветку не трогаем: она ставится Alpine-классом по openId.
    const target = openId > 0 ? openId : 0;

    let retries = 0;
    const tryApplyGridFilter = () => {
        const frame = window.wp?.media?.frame;
        const content = frame?.content?.get?.();
        const library = frame?.state?.()?.get?.('library');
        if (content?.collection?.props || library?.props) {
            // resetPage форсит fetch даже когда текущий фильтр грида уже == target (при первом
            // boot props пустой → currentFolder=0, и без resetPage guard applyFolderFilter мог бы
            // пропустить наполнение грида для target=0).
            store.applyFolderFilter(target, { resetPage: true });
            return;
        }
        if (++retries < 20) {
            setTimeout(tryApplyGridFilter, 150);
        }
    };
    tryApplyGridFilter();
}

/**
 * [internal]: WP core view-switch ссылка (переключатель Сетка/Список) не сохраняет НИ
 * ОДИН query-параметр (подтверждено живым стендом: attachment-filter, s, m — все теряются
 * одинаково, href строится WP core с нуля от admin_url()). В grid-режиме
 * StaticListNavigationManager (manager.js) не инициализирован вообще — это отдельный,
 * Backbone-driven bootstrap-путь (bootstrapStaticGrid), поэтому фикс здесь не через
 * navigate()/AJAX (той инфраструктуры тут нет и architecturally не должно быть), а через
 * простую переписку href перед стандартной браузерной навигацией — переключение режима
 * и так даёт полную перезагрузку страницы по дизайну WP core UX, это не меняется.
 *
 * [internal] (третий заход): источник истины — БЫЛ текущий URL страницы в момент клика
 * (store.openId не синкается с корзиной при URL-заходе, см. spec/_done/[internal]
 * OQ-4). Переопределено этим пакетом — live URL сам ненадёжен: WP core media-grid
 * стирает attachment-filter из адресной строки асинхронно после boot (диагностика,
 * spec/[internal]). Источник истины теперь — isTrashViewActive()
 * (runtime.js) — снимок initial URL при загрузке + событийная инвалидация через
 * plathix.folderFilterApplied, не подверженная этому стиранию.
 */
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
            // Малоформный href — оставить как есть, ничего не переписывать.
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

    // infiniteScroll — top-level флаг (getRuntime), НЕ features[]: PHP кладёт его top-level,
    // а getFeatures().infiniteScroll давал ложный always-true (undefined!==false).
    if (getRuntime().infiniteScroll) {
        infiniteScrollManager.init();
    }

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

    applyInitialStaticGridFilter();
    bindViewSwitchTrashPreservation();

    if (getRuntime().infiniteScroll) {
        attachInitialInfiniteScrollFrame();
    }

    // Корзина папок монтируется trash-entry.js ([internal]).
}
