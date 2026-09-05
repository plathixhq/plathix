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
import { guardTrashUrl } from './static-list/history.js';
import '../../css/sidebar.css';

export const PLATHIX_STORE_KEY = 'plathix';

function registerAlpineBindings() {
    document.addEventListener('alpine:init', () => {
        Alpine.store(PLATHIX_STORE_KEY, sidebarStore);
        Alpine.data('folderTree', folderTree);
        Alpine.data('contextMenu', contextMenuComponent);
        // Alpine.data('colorPicker') уехал в модуль resources/js/sidebar/color/color-entry.js
        // ([internal]): регистрируется там на plathix:ready. Без модуля пикера нет.
        Alpine.data('bulkActions', bulkActionsComponent);
        // Билдер шорткодов (Alpine.data 'shortcodeBuilder' + overlay + store-модуль) уехал в
        // PRO-бандл shortcode-builder.js ([internal]). На медиатеке PRO энкьюит его рядом с
        // sidebar; бандл сам регистрирует компонент/overlay. sidebar лишь ТРИГГЕРИТ открытие
        // через $store.plathix.openShortcodeBuilder(). Без PRO билдера нет (features-флаг off).
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
    // Публичный контракт Free→PRO: сброс клиентского media-grid кэша. PRO-бандлы (напр.
    // folder-upload) не могут очистить Free-приватный _mem своим импортом — очистили бы пустую
    // PRO-копию. Экспонируем сам Free-memClear в window.Plathix ([internal]).
    window.Plathix.mediaGridClear = memClear;
    registerAlpineBindings();

    if (shouldUseMediaFrameFiltering()) {
        stripInitialFolderParamForMediaFrame();
    }

    window.Alpine = Alpine;
    Alpine.start();

    // [internal] ([internal], #827): установить URL-guard ДО любого
    // bootstrap-пути — конкретно до bootstrapStaticGrid(), где инициализируется
    // wp.media/Backbone (единственный чужой актор, стирающий attachment-filter
    // асинхронно через history.replaceState). Установка здесь гарантирует, что
    // guard активен раньше, чем WP core успеет получить шанс на первый вызов.
    guardTrashUrl(isTrashViewActive);

    if (shouldUseStaticListFiltering()) {
        bootstrapStaticList();
    } else if (shouldUseMediaFrameFiltering() && isStaticScreen()) {
        bootstrapStaticGrid();
    } else if (shouldUseMediaFrameFiltering()) {
        bootstrapModal();
    } else {
        // [internal]: ни один из трёх PHP-контролируемых bootstrap-путей не совпал.
        // При текущем коде SidebarScreenResolver::get_filter_strategy() это недостижимо в
        // штатном потоке (только 'media-frame'/'static-list' уходят в JS), но эта ветка не
        // защищена от будущей PHP-регрессии/нового screen_context — без сигнала сайдбар
        // молча оставался бы смонтированным, но полностью неинтерактивным (DnD/upload-sync/
        // infinite-scroll не подключены).
        // [internal]: console.warn один виден только тому, кто открыл DevTools — обычный
        // WP-редактор его не увидит. doAction() даёт машинный сигнал (будущий admin_notice/
        // PRO-модуль) БЕЗ debug-гейта — иначе подписчик на проде (без WP_DEBUG) эту ветку
        // никогда бы не увидел (WP Senior Dev skeptic pass). console.warn гейтится отдельно
        // через window.Plathix.debug (уже кладётся в конфиг, SidebarRuntimeConfigBuilder.php)
        // — не шумит в консоли обычных пользователей WP.org-дистрибуции.
        doAction('plathix.sidebarBootstrapFallback', { filterStrategy: getFilterStrategy(), screenKind: getScreenKind() });
        if (window.Plathix?.debug) {
            console.warn(
                `Plathix sidebar: no bootstrap path matched (filterStrategy=${getFilterStrategy()}, screenKind=${getScreenKind()}). DnD/upload-sync/infinite-scroll will not be active.`
            );
        }
    }

    bindBeforeUnloadPersistence();

    // [internal] ([internal]/L8): раньше здесь безусловно (при defer=false)
    // вызывался refreshFolders({silent:true}) → лишний REST GET /plathix/v1/folders за
    // деревом, которое bootstrap уже целиком положил в window.Plathix.folders. На реальном
    // сайте это лишний полный WP-bootstrap round-trip (~50–90 мс) на КАЖДУЮ загрузку
    // медиатеки при ≤200 папках.
    //
    // При defer=false стор уже инициализирован полным деревом из bootstrap
    // (tree-state.js: folders=runtime.folders, hasLoadedFullTree=true), а payload теперь
    // эквивалентен REST (hasChildren/count — SidebarRuntimeConfigBuilder), поэтому
    // дозапрос ничего не добавляет и снят. При defer=true (>200 папок, lazy) этот блок и
    // раньше не срабатывал — дерево догружается лениво (loadFolderChildren /
    // loadCompleteFolderTree) по взаимодействию. Мутации (crud/move/delete) вызывают
    // refreshFolders адресно — они не затронуты.

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
