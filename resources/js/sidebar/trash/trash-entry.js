/**
 * Автономный entry фичи корзины папок ([internal]).
 *
 * Собирает ВСЮ trash-UI в один инклюд: кнопки «Move to Trash» / «Restore» в toolbar,
 * overlays подтверждения/прогресса, панели удалённых папок (grid + list). Без этого
 * entry платформенный сайдбар работает нормально — кнопок нет, диалогов нет,
 * панель не монтируется (graceful degradation).
 *
 * Монтаж: на plathix:ready вставляет разметку в data-slot="plathix-trash-actions"
 * и data-slot="plathix-trash-overlay" через initTree на window.Alpine (КРИТИЧНО:
 * строго window.Alpine, иначе $store.plathix не резолвится в новом узле — прецедент
 * [internal] skeptic X2). Инициализирует панели корзины папок.
 * MutationObserver обеспечивает ремонтаж при пересоздании DOM (childList+subtree).
 */
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
    // КРИТИЧНО: initTree строго на window.Alpine (инстанс сайдбара со store),
    // иначе $store.plathix не резолвится в новом узле (прецедент skeptic X2).
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

/**
 * Домёргивает store._trashImpl ([internal], паттерн _colorImpl): toolbar-условие
 * читает isCurrentFolderTrashed() через кеш id удалённых папок (trash-core.js).
 * Кеш обновляется на смену папки (folderOpened) и на удаление/восстановление
 * папки (FOLDER_DELETED, restore из панели плиток) — те же триггеры, что уже
 * использует trash-panel.js для своего рефреша.
 */
function initTrashToolbarImpl(store) {
    store._trashImpl = {
        isCurrentFolderTrashed() {
            const ids = getCachedTrashedFolderIds();
            return ids.has(Number(store.openId));
        },
    };

    // _trashedFolderIdsVersion — реактивный триггер ([internal], найдено на browser proof):
    // без инкремента Alpine x-show не переоценивает isCurrentFolderTrashed() после того,
    // как кеш реально заполнился (module-level Set сам по себе не Alpine-реактивен).
    // Подписка покрывает ОБА источника обновления кеша: refreshTrashedFolderIds() (вызов
    // ниже/на folderOpened) И fetchAndRenderTiles() (панель плиток restore/purge).
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

    // [internal]: на первой загрузке страницы (прямой URL-заход,
    // без клика по дереву) .media-toolbar монтируется ПОЗЖЕ, чем срабатывает первичный
    // refresh() внутри initTrashToolbarImpl — querySelector('.media-toolbar') в этот момент
    // возвращает null, маркер молча не проставляется (browser proof: прямой URL-заход в
    // Trash, класс не появился). Переиспользуем уже существующий observer (не заводим
    // второй) — вызываем ТОЛЬКО дешёвый idempotent syncMediaToolbarTrashClass (classList
    // toggle), а не дорогой refresh() (сетевой refreshTrashedFolderIds), чтобы не давать
    // сетевой нагрузки на каждую DOM-мутацию сетки медиатеки (WP Senior Dev skeptic).
    const mo = new MutationObserver(() => {
        fillAllSlots(A);
        if (store) {
            syncMediaToolbarTrashClass(store);
        }
    });
    mo.observe(document.body, { childList: true, subtree: true });
    // [internal] ([internal]): наблюдатель живёт весь page load, production не
    // вызывает disconnect() — сохранён для тестового cleanup, паттерн dnd.js.
    setStateValue('trashEntryBodyObserver', mo);
}

if (window.__PlathixApiReady) {
    onPlathixReady();
} else {
    window.addEventListener('plathix:ready', onPlathixReady, { once: true });
}
