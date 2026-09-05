import { colorPickerComponent } from './color-picker-component.js';
import { colorShowImpl } from './color-show.js';
import { setStateValue } from '../state.js';
import './color.css';

/**
 * Автономный entry фичи цвета папки ([internal], форма v4).
 *
 * Собирает ВСЮ фичу цвета в один инклюд: пикер (Alpine, folder из store), показ (домёрж в
 * store), самомонтаж разметки в контекст-меню. Правка цвета = правка только модуля color/.
 * Будущий вынос в PRO = mv папки + webpack-entry + PHP-i18n + смена условия монтажа.
 *
 * Монтаж: на plathix:ready регистрирует Alpine-компонент colorPicker, домёрживает показ в
 * store, вставляет разметку пикера в слот plathix-context-menu-items через insertOrdered
 * (ORDER=30 — ниже PRO Gallery=10/ZIP=20, но выше статического «Удалить»: HARD-инвариант
 * «Цвет над Удалить»), оживляет узел через window.Alpine.initTree. MutationObserver
 * перемонтирует при ре-рендере меню.
 */

const MARKER = 'plathix-color-ctx-item';
const ORDER = 30; // > 20 (ZIP), < статического «Удалить» вне слота — [internal] инвариант

/**
 * Упорядоченная вставка по числовому data-order (своя копия — insertOrdered из plathixPro
 * во Free недоступен; модуль автономен). Меньше order = выше. Идемпотентность на вызывающем.
 */
function insertOrdered(slot, node, order) {
    node.setAttribute('data-order', String(order));
    const before = Array.from(slot.children).find((el) => {
        const raw = el.getAttribute('data-order');
        return (raw === null ? Infinity : Number(raw)) > order;
    });
    if (before) {
        slot.insertBefore(node, before);
    } else {
        slot.appendChild(node);
    }
}

function colorItemHTML(label) {
    return `<div class="plathix-context-menu__color ${MARKER}" x-data="colorPicker" x-effect="syncFromStore()" x-show="$store.plathix.canManage && !_folder?.isProtected">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
        ${label}
        <span class="plathix-color__controls">
            <span class="plathix-color__swatch" :class="{ 'plathix-color__swatch--empty': !hasColor }" :style="hasColor ? ('background:' + color) : ''">
                <input type="color" :value="color" @input="set($event.target.value)" @change="set($event.target.value)">
            </span>
        </span>
    </div>`;
}

/**
 * Монтирует СВЕЖИЙ узел пикера в слот. force=true — удаляет прежний узел и монтирует заново
 * (воспроизводит «чистый монтаж как после ребута»: нативный <input type=color> перерисует
 * видимый кружок при СМЕНЕ папки — [internal] путь Г). force=false (первичный проход /
 * MutationObserver) — idempotent: не трогает уже смонтированный.
 */
function fillSlot(slot, label, A, force) {
    const existing = slot.querySelector('.' + MARKER);
    if (existing) {
        if (!force) {
            return;
        }
        existing.remove(); // форс-ремонтаж: старый узел с несвежим нативным пикером — прочь
    }
    const tmp = document.createElement('div');
    tmp.innerHTML = colorItemHTML(label);
    const node = tmp.firstElementChild;
    insertOrdered(slot, node, ORDER);
    // КРИТИЧНО (skeptic X2): initTree строго на window.Alpine (инстанс сайдбара со store),
    // иначе узел уйдёт в мёртвый инстанс и $store.plathix не резолвится.
    if (typeof A.initTree === 'function') {
        A.initTree(node);
    }
}

function fillAllSlots(label, A, force) {
    document
        .querySelectorAll('[data-slot="plathix-context-menu-items"]')
        .forEach((slot) => fillSlot(slot, label, A, force));
}

function onPlathixReady() {
    const A = window.Alpine;
    if (!A) {
        return;
    }
    // Компонент пикера — Alpine.data (регистрируем здесь, а не в общем index.js).
    A.data('colorPicker', colorPickerComponent);

    // Домёрж показа в store (реальная реализация поверх stub в ui-state.js).
    const store = A.store('plathix');
    if (store) {
        store._colorImpl = colorShowImpl;
    }

    const label = window.Plathix?.i18n?.color_label || 'Color';
    fillAllSlots(label, A, false);

    // Слот context-menu переиспользуется (open() меняет атрибут, не пересоздаёт узел), но при
    // ре-рендере дерева/меню слоты могут пересоздаваться — домонтируем по childList (idempotent).
    const mo = new MutationObserver(() => fillAllSlots(label, A, false));
    mo.observe(document.body, { childList: true, subtree: true });
    // [internal] ([internal]): наблюдатель живёт весь page load, production не
    // вызывает disconnect() — сохранён для тестового cleanup, паттерн dnd.js.
    setStateValue('colorEntryBodyObserver', mo);

    // ФОРС-РЕМОНТАЖ при смене папки ([internal] путь Г). contextMenuFolderId реактивен в store;
    // A.effect пере-выполняется при открытии меню на новой папке. Свежий монтаж узла = «как после
    // ребута» → нативный <input type=color> надёжно перерисует видимый кружок в цвет новой папки.
    // Без этого узел переиспользуется (idempotent guard), и нативный контрол не репейнтит видимый
    // swatch при смене contextMenuFolderId, хотя .value верный (баг «красится только после ребута»).
    /** @type {any} */ (A).effect(() => {
        const id = Number(A.store('plathix').contextMenuFolderId) || 0;
        if (id > 0) {
            fillAllSlots(label, A, true);
        }
    });
}

if (window.__PlathixApiReady) {
    onPlathixReady();
} else {
    window.addEventListener('plathix:ready', onPlathixReady, { once: true });
}
