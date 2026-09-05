import { Api } from '../api.js';
import { getRuntime } from '../runtime.js';
import { t } from '../i18n.js';

// [internal] ([internal]): последнее ПОДТВЕРЖДЁННОЕ сервером состояние избранного —
// baseline отката при провале сохранения. До первого успешного ответа это runtime-снимок,
// с которым отрисовался сайдбар. Обновляется только копией фактически отправленного
// массива в .then() — не живой ссылкой, которую следующий toggle успел бы мутировать.
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
                // AbortError — запрос вытеснен следующим toggle (abort-guard в api.js):
                // не провал, его изменения несёт следующий запрос полным массивом.
                if (error?.name === 'AbortError') {
                    return;
                }
                // Откат к последнему подтверждённому состоянию (не к снапшоту до мутации:
                // при двух провалах подряд снапшот второго = неподтверждённый итог первого).
                // notify — optional call: модуль автономен и не падает в host без notifications.
                this.favorites = [..._lastSyncedFavorites];
                this._favoritesSet = new Set(_lastSyncedFavorites);
                this.notify?.('error', t('favorites_save_failed', 'Failed to save favorites.'));
            });
    },
};
