import './search/search.css';
import { searchModule } from './store/search.js';
import { t } from './i18n.js';

const searchWrapHTML = () => `
    <div class="plathix-search__wrap" x-data="{ showSort: false }">
        <div class="plathix-search__inner">
            <span class="plathix-search__icon">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="search" class="plathix-search__input" @input.debounce.500ms="$store.plathix.setSearchQuery($event.target.value)" placeholder="${t('search_folders', 'Search folders')}">
            <span class="plathix-search-spinner" x-show="$store.plathix.isSearching"></span>
            <button type="button" class="plathix-sort__btn" title="${t('sort_folders', 'Sort')}" @click.stop="showSort = !showSort" :class="{ 'is-active': showSort || $store.plathix.sortBy !== 'default' }">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg>
            </button>
        </div>
        <div class="plathix-sort__dropdown" x-show="showSort" @click.outside="showSort = false" x-cloak>
            <div class="plathix-sort__header">${t('sort_label', 'SORT')}</div>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'default' }" @click="$store.plathix.setSortBy('default'); showSort = false">
                <span>${t('sort_default', 'By default')}</span>
                <svg x-show="$store.plathix.sortBy === 'default'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'alpha' }" @click="$store.plathix.setSortBy('alpha'); showSort = false">
                <span>${t('sort_alpha_az', 'A → Z')}</span>
                <svg x-show="$store.plathix.sortBy === 'alpha'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'alpha_z' }" @click="$store.plathix.setSortBy('alpha_z'); showSort = false">
                <span>${t('sort_alpha_za', 'Z → A')}</span>
                <svg x-show="$store.plathix.sortBy === 'alpha_z'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'new' }" @click="$store.plathix.setSortBy('new'); showSort = false">
                <span>${t('sort_new', 'Newest first')}</span>
                <svg x-show="$store.plathix.sortBy === 'new'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'old' }" @click="$store.plathix.setSortBy('old'); showSort = false">
                <span>${t('sort_old', 'Oldest first')}</span>
                <svg x-show="$store.plathix.sortBy === 'old'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
            <button type="button" class="plathix-sort__opt" :class="{ 'is-active': $store.plathix.sortBy === 'size' }" @click="$store.plathix.setSortBy('size'); showSort = false">
                <span>${t('sort_size', 'By size')}</span>
                <svg x-show="$store.plathix.sortBy === 'size'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </div>
`;

function onPlathixReady() {
    const store = window.Alpine?.store('plathix');
    if (store) {
        const impl = {};
        for (const [key, desc] of Object.entries(Object.getOwnPropertyDescriptors(searchModule))) {
            if (typeof desc.get === 'function') {
                impl[key] = desc.get;
            } else if (typeof desc.value === 'function') {
                impl[key] = desc.value;
            }
        }
        store._searchImpl = impl;

        // Геттеры делегируют через _searchImpl.xxx.call(this) — работают.
        // Методы-действия в searchStubs — no-op заглушки; патчим реальными из searchModule.
        store.setSearchQuery = searchModule.setSearchQuery.bind(store);
        store.clearSearch    = searchModule.clearSearch.bind(store);
        store.setSortBy      = searchModule.setSortBy.bind(store);
    }

    const slot = document.querySelector('[data-slot="plathix-search"]');
    if (slot) {
        slot.outerHTML = searchWrapHTML();
    }
}

// Guard: если sidebar.js загружен с defer="", plathix:ready может выстрелить
// до того как этот бандл выполнился. __PlathixApiReady — флаг из index.js:90.
if (window.__PlathixApiReady) {
    onPlathixReady();
} else {
    window.addEventListener('plathix:ready', onPlathixReady, { once: true });
}
