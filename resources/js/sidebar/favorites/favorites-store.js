import { Api } from '../api.js';
import { getRuntime } from '../runtime.js';
import { t } from '../i18n.js';





let _lastSyncedFavorites = (getRuntime().favorites || []).map(Number);

export const favoritesModule = {
    favorites: (getRuntime().favorites || []).map(Number),
    _favoritesSet: new Set((getRuntime().favorites || []).map(Number)),

    isFavorite(id) {
        return this._favoritesSet.has(Number(id));
    },

    toggleFavorite(id) {
        const folderId = Number(id);
        const idx = this.favorites.indexOf(folderId);
        if (idx === -1) {
            this.favorites.push(folderId);
            this._favoritesSet.add(folderId);
        } else {
            this.favorites.splice(idx, 1);
            this._favoritesSet.delete(folderId);
        }
        const sent = [...this.favorites];
        Api.saveFavorites(sent)
            .then(() => {
                _lastSyncedFavorites = sent;
            })
            .catch((error) => {


                if (error?.name === 'AbortError') {
                    return;
                }



                this.favorites = [..._lastSyncedFavorites];
                this._favoritesSet = new Set(_lastSyncedFavorites);
                this.notify?.('error', t('favorites_save_failed', 'Failed to save favorites.'));
            });
    },
};
