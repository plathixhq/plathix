import { t } from '../i18n.js';

export const favoritesTemplate = () => `
    <template x-if="$store.plathix.hasVisibleFavorites">
        <div x-data="{
            open: localStorage.getItem('plathix_fav_open') !== '0',
            toggle() { this.open = !this.open; localStorage.setItem('plathix_fav_open', this.open ? '1' : '0'); }
        }">
            <div class="plathix-favorites__block">
                <button type="button" class="plathix-favorites__title" @click="toggle()" :aria-expanded="open">
                    <svg class="plathix-favorites__chevron" :class="{ 'is-collapsed': !open }" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    <svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    ${t('favorites', 'Favorites')}
                    <span class="plathix-favorites__count" x-text="$store.plathix.visibleFavoritesCount"></span>
                </button>
                <div x-show="open">
                    <template x-for="fid in $store.plathix.favorites" :key="fid">
                        <div x-data="{ get favFolder() { return $store.plathix.folders.find(f => Number(f.id) === Number(fid)) || null; } }">
                            <template x-if="favFolder !== null && $store.plathix.favoriteMatchesSearch(favFolder)">
                                <div class="plathix-folder"
                                     :data-folder-id="fid"
                                     tabindex="0"
                                     role="button"
                                     :class="{ 'is-open': Number($store.plathix.openId) === Number(fid) }"
                                     @click="$store.plathix.openFolder(Number(fid))"
                                     @keydown.enter.prevent="$store.plathix.openFolder(Number(fid))"
                                     @keydown.space.prevent="$store.plathix.openFolder(Number(fid))">
                                    <span class="plathix-collapse__placeholder"></span>
                                    <svg class="plathix-folder__icon"
                                         :style="$store.plathix.folderColorStyle(favFolder)"
                                         width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path :fill="$store.plathix.folderColorFill(favFolder)" d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                                    </svg>
                                    <span class="plathix-folder__name" x-text="favFolder.name"></span>
                                    <span class="count" x-text="favFolder.count" x-show="favFolder.count > 0"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
            <hr class="plathix-block__divider">
        </div>
    </template>
`;
