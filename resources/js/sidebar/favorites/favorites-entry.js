/**
 * Автономный entry фичи избранных папок ([internal] + [internal]).
 *
 * 1. Монтирует блок «ИЗБРАННОЕ» в data-slot="plathix-favorites" после plathix:ready.
 * 2. Монтирует кнопку-звёздочку в data-slot="plathix-context-menu-top" (ORDER=5,
 *    первой в меню, до hr — выше «Новая подпапка»/«Переименовать»/PRO/Color).
 *
 * Домёрживает реальный favoritesModule поверх stubs через Object.assign — Alpine
 * proxy вызовет set-handler на каждый ключ, x-if реактивно пересчитается.
 * Без этого entry слоты остаются пустыми (graceful degradation).
 *
 * КРИТИЧНО: initTree строго на window.Alpine (инстанс сайдбара со store),
 * иначе $store.plathix не резолвится в новом узле (прецедент [internal] skeptic X2).
 */
import './favorites.css';
import { favoritesModule } from './favorites-store.js';
import { favoritesTemplate } from './favorites-template.js';
import { setStateValue } from '../state.js';
import { escapeAttr } from '../utils/escape.js';

const SLOT = 'plathix-favorites';
const MARKER = 'plathix-favorites-entry';

// ── Context-menu top slot ([internal]) ─────────────────────────────
// Слот plathix-context-menu-top стоит выше hr — «Избранное» первым пунктом,
// как было до [internal]. Слот plathix-context-menu-items остался для
// PRO (builder/zip) и Color (ORDER=30).
const CTX_MARKER = 'plathix-fav-ctx-item';
const CTX_ORDER = 5; // ORDER сохранён для insertOrdered — пригодится если в топ-слот добавят ещё пункты

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

export function favCtxItemHTML(addLabel, removeLabel) {
    return `<button type="button"
        class="plathix-context-menu__favorite ${CTX_MARKER}"
        x-show="Number($store.plathix.contextMenuFolderId) > 0 && !$store.plathix.folders.find(f => Number(f.id) === Number($store.plathix.contextMenuFolderId))?.isProtected"
        @click="$store.plathix.toggleFavorite(Number($store.plathix.contextMenuFolderId)); window.dispatchEvent(new CustomEvent('plathix:ctx-close'))"
        :class="{ 'plathix-context-menu__favorite--active': $store.plathix.isFavorite($store.plathix.contextMenuFolderId) }">
        <svg width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
             :fill="$store.plathix.isFavorite($store.plathix.contextMenuFolderId) ? 'currentColor' : 'none'"
             :stroke="$store.plathix.isFavorite($store.plathix.contextMenuFolderId) ? 'currentColor' : 'currentColor'">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <span x-text="$store.plathix.isFavorite($store.plathix.contextMenuFolderId) ? ${escapeAttr(JSON.stringify(removeLabel))} : ${escapeAttr(JSON.stringify(addLabel))}"></span>
    </button>`;
}

function fillCtxSlot(slot, addLabel, removeLabel, A) {
    if (slot.querySelector('.' + CTX_MARKER)) {
        return;
    }
    const tmp = document.createElement('div');
    tmp.innerHTML = favCtxItemHTML(addLabel, removeLabel);
    const node = tmp.firstElementChild;
    insertOrdered(slot, node, CTX_ORDER);
    // КРИТИЧНО: initTree на window.Alpine (прецедент [internal] skeptic X2)
    if (typeof A.initTree === 'function') {
        A.initTree(node);
    }
}

function fillAllCtxSlots(addLabel, removeLabel, A) {
    document
        .querySelectorAll('[data-slot="plathix-context-menu-top"]')
        .forEach((slot) => fillCtxSlot(slot, addLabel, removeLabel, A));
}
// ─────────────────────────────────────────────────────────────────────────────

function onPlathixReady() {
    const A = window.Alpine;
    if (!A) {
        return;
    }

    const store = A.store('plathix');
    if (store) {
        Object.assign(store, favoritesModule);
        store._favoritesSet = new Set(store.favorites);
    }

    const slot = document.querySelector('[data-slot="' + SLOT + '"]');
    if (!slot || slot.querySelector('.' + MARKER)) {
        return;
    }

    const tmp = document.createElement('div');
    tmp.innerHTML = favoritesTemplate();
    const node = tmp.firstElementChild;
    node.classList.add(MARKER);
    slot.replaceWith(node);
    A.initTree(node);

    // Монтаж кнопки в контекстное меню
    const addLabel = window.Plathix?.i18n?.add_favorite || 'Add to favorites';
    const removeLabel = window.Plathix?.i18n?.remove_favorite || 'Remove from favorites';
    fillAllCtxSlots(addLabel, removeLabel, A);

    // Перемонтаж при ре-рендере дерева/меню (как в color-entry.js)
    const mo = new MutationObserver(() => fillAllCtxSlots(addLabel, removeLabel, A));
    mo.observe(document.body, { childList: true, subtree: true });
    // [internal] ([internal]): наблюдатель живёт весь page load, production не
    // вызывает disconnect() — сохранён для тестового cleanup, паттерн dnd.js.
    setStateValue('favoritesEntryBodyObserver', mo);
}

if (window.__PlathixApiReady) {
    onPlathixReady();
} else {
    window.addEventListener('plathix:ready', onPlathixReady, { once: true });
}
