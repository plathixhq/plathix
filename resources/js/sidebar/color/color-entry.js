import { colorPickerComponent } from './color-picker-component.js';
import { colorShowImpl } from './color-show.js';
import { setStateValue } from '../state.js';
import './color.css';




const MARKER = 'plathix-color-ctx-item';
const ORDER = 30;



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



function fillSlot(slot, label, A, force) {
    const existing = slot.querySelector('.' + MARKER);
    if (existing) {
        if (!force) {
            return;
        }
        existing.remove();
    }
    const tmp = document.createElement('div');
    tmp.innerHTML = colorItemHTML(label);
    const node = tmp.firstElementChild;
    insertOrdered(slot, node, ORDER);


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

    A.data('colorPicker', colorPickerComponent);


    const store = A.store('plathix');
    if (store) {
        store._colorImpl = colorShowImpl;
    }

    const label = window.Plathix?.i18n?.color_label || 'Color';
    fillAllSlots(label, A, false);



    const mo = new MutationObserver(() => fillAllSlots(label, A, false));
    mo.observe(document.body, { childList: true, subtree: true });


    setStateValue('colorEntryBodyObserver', mo);






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
