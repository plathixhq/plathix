/**
 * Панель корзины папок ([internal] / UI-FIX-202,203).
 *
 * Собственный блок вывода: при открытой системной папке «Корзина» монтирует свой контейнер
 * МЕЖДУ нативными `.media-toolbar.wp-filter` и `.attachments-wrapper` (требование пользователя),
 * НАД списком файлов-в-корзине (WP рисует файлы сам, его вывод не трогаем), рендерит удалённые
 * ПАПКИ квадратными плитками с restore/purge.
 *
 * УСТОЙЧИВОСТЬ К BACKBONE (скептик WP Senior Dev, conditional PASS): `.attachments-browser`
 * перерисовывается часто (скролл/фильтр/подгрузка). Разделяем ДАННЫЕ и ПОЗИЦИЮ:
 *  - fetch+рендер плиток — ТОЛЬКО на смену папки (folderOpened) и удаление (FOLDER_DELETED),
 *    не на каждую перерисовку (иначе шквал REST);
 *  - MutationObserver лишь ПЕРЕСТАВЛЯЕТ уже готовый узел на место, если WP его выкинул —
 *    без нового fetch; guard «узел уже на месте» гасит цикл; wrapper re-query каждый раз (не stale).
 *
 * Переиспользуемость (Free/PRO): trashFolderId/postType из runtime; PRO подключает тот же модуль.
 */
import { getRuntime } from '../runtime.js';
import { Events } from '../events.js';
// Общее ядро (LMP-102): fetch+рендер+действия переиспускаются grid и list модулями.
import { CONTAINER_ID, fetchAndRenderTiles } from './trash-core.js';
import { setStateValue } from '../state.js';

const BROWSER_SEL = '.attachments-browser';
const WRAPPER_SEL = '.attachments-wrapper';

let mountObserver = null;

/** Активна ли сейчас корзина: выбранная папка совпадает с системной Trash. */
function isTrashOpen(store) {
    const trashId = Number(getRuntime().trashFolderId || 0);
    return trashId > 0 && Number(store?.openId || 0) === trashId;
}

export function initFolderTrashPanel(store) {
    if (!store) {
        return;
    }

    // fetch+рендер — только на смену папки и удаление (не на перерисовку Backbone).
    const refresh = () => {
        if (isTrashOpen(store)) {
            renderPanel(store);
        } else {
            removePanel();
        }
    };

    window.wp?.hooks?.addAction?.('plathix.folderOpened', 'plathix/folder-trash-panel', refresh);
    // Интерактивность (UI-FIX-202): удалил папку, находясь в корзине → сразу перерисовать плитки.
    window.addEventListener(Events.FOLDER_DELETED, refresh);

    // Позиционирование: observer лишь возвращает готовый узел на место после перерисовки,
    // без fetch. Guard «уже на месте» + re-query wrapper гасят цикл и stale-ref.
    ensureObserver();

    // Монтирование при загрузке ([internal]). Восстановление открытой папки при F5
    // идёт через applyFolderFilter (bootstrap), а НЕ через openFolder → хук folderOpened НЕ
    // эмитится. Одиночный refresh() проваливался, если openId/DOM ещё не готовы, без ретрая —
    // блок не появлялся до нового клика. Ретрай (паттерн bootstrap 20×150мс) закрывает и
    // restore-без-folderOpened, и гонку готовности медиа-области.
    scheduleInitialMount(store, refresh);
}

/**
 * Повторяет попытку смонтировать блок, пока корзина открыта и область медиа не готова.
 * Останавливается когда: блок на месте, корзина закрыта, или лимит попыток исчерпан.
 * `refresh` передаётся из initFolderTrashPanel (замыкание над store) — НЕ ссылаться на него
 * как на глобаль, иначе ReferenceError и весь init падает (баг первой версии [internal]).
 */
function scheduleInitialMount(store, refresh) {
    let retries = 0;
    const tryMount = () => {
        // ВАЖНО: при F5 store.openId восстанавливается из preference НЕ мгновенно — в первые
        // тики после DOMContentLoaded openId ещё 0 (не в корзине). Нельзя выходить навсегда на
        // первом «не в корзине» — надо продолжать ретраить, пока openId не восстановится ИЛИ
        // лимит не исчерпан. Иначе блок не появляется при перезагрузке (корень [internal]).
        if (isTrashOpen(store)) {
            refresh();
            // «Смонтировано» = контейнер существует И стоит на месте (родитель=browser, next=wrapper).
            // Пустая корзина (0 папок) — валидное состояние: контейнер на месте, innerHTML пуст.
            const container = document.getElementById(CONTAINER_ID);
            const wrapper = browserEl()?.querySelector(`:scope > ${WRAPPER_SEL}`) || document.querySelector(WRAPPER_SEL);
            const mounted = !!container && container.parentElement === browserEl() && container.nextElementSibling === wrapper;
            if (mounted) {
                return;
            }
        }
        if (++retries < 20) {
            setTimeout(tryMount, 150);
        }
    };
    tryMount();
}

/** Целевой родитель (.attachments-browser) — актуальный на момент вызова, не кэшируем. */
function browserEl() {
    return document.querySelector(BROWSER_SEL);
}

/** Ставит существующий контейнер МЕЖДУ toolbar и wrapper. Не создаёт, не фетчит. */
function positionContainer(container) {
    const browser = browserEl();
    const wrapper = browser?.querySelector(`:scope > ${WRAPPER_SEL}`) || document.querySelector(WRAPPER_SEL);
    if (!browser || !wrapper) {
        return false;
    }
    // Guard «уже на месте» — гасит цикл observer'а.
    if (container.parentElement === browser && container.nextElementSibling === wrapper) {
        return true;
    }
    browser.insertBefore(container, wrapper);
    return true;
}

function ensureObserver() {
    if (mountObserver) {
        return;
    }
    mountObserver = new MutationObserver(() => {
        const container = document.getElementById(CONTAINER_ID);
        // Только переставить готовый узел; если его нет (не в корзине) — ничего не делаем.
        if (container) {
            positionContainer(container);
        }
    });
    const browser = browserEl();
    if (browser) {
        mountObserver.observe(browser, { childList: true });
        // [internal] ([internal]): наблюдатель живёт весь page load, production
        // не вызывает disconnect() — сохранён для тестового cleanup, паттерн dnd.js.
        setStateValue('trashPanelMountObserver', mountObserver);
    }
}

function removePanel() {
    document.getElementById(CONTAINER_ID)?.remove();
}

/**
 * Grid-монтирование: создаёт/находит контейнер, ставит его между toolbar и wrapper (Backbone-DOM),
 * подключает observer, затем делегирует fetch+рендер+действия общему ядру (LMP-102).
 */
async function renderPanel(store) {
    let container = document.getElementById(CONTAINER_ID);
    if (!container) {
        container = document.createElement('div');
        container.id = CONTAINER_ID;
        container.className = 'plathix-folder-trash-panel';
    }
    if (!positionContainer(container)) {
        return; // область медиа ещё не готова
    }
    ensureObserver();

    await fetchAndRenderTiles(container, store);
}
