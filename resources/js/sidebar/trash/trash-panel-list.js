/**
 * Панель корзины папок для LIST-режима медиатеки ([internal] LMP-103).
 *
 * Grid-версия (`folder-trash-panel.js`) прибита к Backbone-DOM (`.attachments-browser`) и триггерится
 * хуком `folderOpened`, которого в list НЕТ. List использует WP_Media_List_Table и фрагмент-навигацию
 * static-list. Поэтому — отдельный модуль (вердикт WP Senior Dev скептика), но контент (плитки,
 * restore/purge) берётся из ОБЩЕГО ядра `folder-trash-core.js`.
 *
 * Якорь: `form#posts-filter` — стабилен, фрагмент-навигация (static-list applyFragments/applyZones)
 * заменяет только его ПОТОМКОВ (#the-list, .tablenav.top, .subsubsub), сам form не трогает. Контейнер
 * вставляется перед `.tablenav.top` (над таблицей файлов), как grid-версия между toolbar и wrapper.
 *
 * Триггеры:
 *  - `plathix.navigationComplete` (эмитится static-list в #finalizeNavigation) — при кликах по папкам;
 *  - `Events.FOLDER_DELETED` — удалил папку, находясь в корзине → перерисовать;
 *  - разовый начальный маунт при init: manager.init() НЕ зовёт navigate → navigationComplete на
 *    F5/прямом заходе НЕ эмитится (факт из кода manager.js:152), поэтому первый показ — вручную.
 */
import { getRuntime } from '../runtime.js';
import { Events } from '../events.js';
import { CONTAINER_ID, fetchAndRenderTiles } from './trash-core.js';

const FORM_SEL = 'form#posts-filter';
const TABLENAV_TOP_SEL = '.tablenav.top';

/** Активна ли корзина: openId совпадает с системной Trash. */
function isTrashOpen(store) {
    const trashId = Number(getRuntime().trashFolderId || 0);
    return trashId > 0 && Number(store?.openId || 0) === trashId;
}

/** Целевая форма-якорь (актуальная на момент вызова, не кэшируем). */
function formEl() {
    return document.querySelector(FORM_SEL);
}

/**
 * Ставит контейнер в форму над таблицей файлов. Возвращает false, если форма ещё не готова.
 * Идемпотентно: guard «уже перед актуальным якорем» гасит лишние вставки.
 *
 * Якорь устойчив к фрагмент-навигации: `.tablenav.top` заменяется static-list при каждой навигации
 * и в момент navigationComplete может временно отсутствовать (гонка). Тогда встаём перед
 * `.wp-list-table`, а если и её нет — в конец формы. Так панель всегда над таблицей, без зависимости
 * от одного узла, который перерисовывается (факт с прода: при возврате в корзину tablenav.top
 * отсутствовал → панель не монтировалась).
 * @param {HTMLElement} container
 */
function positionContainer(container) {
    const form = formEl();
    if (!form) {
        return false;
    }
    const anchor = form.querySelector(TABLENAV_TOP_SEL) || form.querySelector('.wp-list-table');
    if (container.parentElement === form && container.nextElementSibling === anchor) {
        return true; // уже на месте перед актуальным якорем
    }
    // anchor может быть null (ни tablenav, ни таблицы) — insertBefore(node, null) = append в конец form.
    form.insertBefore(container, anchor);
    return true;
}

/** Убирает панель (когда корзина закрыта). */
function removePanel() {
    document.getElementById(CONTAINER_ID)?.remove();
}

/**
 * Монтирует контейнер в form и делегирует рендер общему ядру. Вне корзины — снимает панель.
 * @param {object} store
 */
async function refresh(store) {
    if (!isTrashOpen(store)) {
        removePanel();
        return;
    }
    let container = document.getElementById(CONTAINER_ID);
    if (!container) {
        container = document.createElement('div');
        container.id = CONTAINER_ID;
        container.className = 'plathix-folder-trash-panel';
    }
    if (!positionContainer(container)) {
        return; // форма/таблица ещё не готовы
    }
    await fetchAndRenderTiles(container, store);
}

/**
 * Разовый начальный маунт с лёгким ретраем: openId из preference может встать не мгновенно после
 * DOMContentLoaded (как в grid-версии [internal], но короче — list-DOM статичен, гонки
 * Backbone-готовности здесь нет). Останавливается: панель на месте, корзина закрыта, лимит исчерпан.
 */
function scheduleInitialMount(store) {
    let retries = 0;
    const tryMount = () => {
        if (isTrashOpen(store)) {
            refresh(store);
            const container = document.getElementById(CONTAINER_ID);
            const form = formEl();
            const tablenavTop = form?.querySelector(TABLENAV_TOP_SEL);
            const mounted = !!container && container.parentElement === form && container.nextElementSibling === tablenavTop;
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

/**
 * Инициализация list-панели корзины. Вызывается из bootstrap-static-list ПОСЛЕ
 * initStaticListNavigation (чтобы navigationComplete уже был доступен).
 * @param {object} store Alpine-store 'plathix'
 */
export function initFolderTrashPanelList(store) {
    if (!store) {
        return;
    }

    // Клики по папкам в list идут через static-list → doAction('plathix.navigationComplete').
    // reposition + перерисовка на каждую навигацию: фрагмент-replace .tablenav.top мог оставить
    // контейнер сиротой — positionContainer вернёт его перед свежий tablenav, refresh обновит данные.
    window.wp?.hooks?.addAction?.('plathix.navigationComplete', 'plathix/folder-trash-panel-list', () => refresh(store));

    // Удалил папку, находясь в корзине → сразу перерисовать плитки.
    window.addEventListener(Events.FOLDER_DELETED, () => refresh(store));

    // Первый показ: manager.init() не эмитит navigationComplete на прямом заходе/F5.
    scheduleInitialMount(store);
}
