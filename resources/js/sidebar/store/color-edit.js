import { Api } from '../api.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';

/**
 * Композиционный корень действия «задать цвет папки» ([internal]).
 *
 * По образцу favoritesModule (store/favorites.js): store-действие фичи-атрибута живёт в
 * своём модуле, а не строкой в чужом CRUD-блоке (раньше — folders-crud.js рядом с
 * create/rename/delete). Подключается в общий store через mergeStore (store.js).
 *
 * ПОКАЗ цвета (folderColorStyle/folderColorFill в store/ui-state.js, safeHexColor в
 * color-utils.js) сюда НЕ переносится намеренно: это платформенный рендер свотча,
 * разделяемый деревом и Favorites-шаблоном (templates/favorites.js) — перенос создал бы
 * обратную зависимость favorites → color-edit. Разметка пикера (templates/overlays.js),
 * Alpine-компонент colorPicker (index.js) и CSS остаются в платформенном UI-слое сайдбара,
 * как звезда Favorites.
 */
export const colorEditModule = {
    async setFolderColor(id, color) {
        // ОПТИМИСТИЧНАЯ мутация ПОЛЯ folders[idx].color синхронно ПЕРЕД await ([internal]):
        // дерево (folder-tree.js folderColorStyle) и свотч перекрашиваются МГНОВЕННО, не дожидаясь
        // REST+refreshFolders (который асинхронно заменяет весь массив — отсюда был лаг «в моменте»).
        // Мутируем ПОЛЕ, а не splice-новый-объект: кэш _childrenByParent держит массив по рефу,
        // splice подставил бы новый объект мимо кэша (skeptic), мутация поля реактивна и видна дереву.
        const idx = this.folders.findIndex((f) => Number(f.id) === Number(id));
        const prevColor = idx !== -1 ? this.folders[idx].color : null;
        if (idx !== -1) {
            this.folders[idx].color = color;
        }
        try {
            await this.withLoading(async () => {
                await Api.setFolderColor(id, color);
                cacheInvalidateFolder(id);
                // [internal] (parity items.js:164): не await — сбой ПОСЛЕДУЮЩЕГО refresh
                // не должен пробрасываться в rethrow-catch ниже и откатывать уже успешно
                // применённый на сервере цвет.
                this.refreshFolders({ silent: true, skipCacheClear: true }).catch(() => {});
            }, { rethrow: true });
        } catch (error) {
            // откат оптимистики при ошибке REST — вернуть прежний цвет
            if (idx !== -1 && this.folders[idx] && Number(this.folders[idx].id) === Number(id)) {
                this.folders[idx].color = prevColor;
            }
            throw error;
        }
    },
};
