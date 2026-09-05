import { getRuntime } from '../runtime.js';

const runtime = getRuntime();

export const uiStateModule = {
    isLoading: !!runtime.deferFoldersBootstrap && !(Array.isArray(runtime.folders) && runtime.folders.length > 0),
    alertMessage: null,
    error: null,
    // Перенесено из мёртвого core.js ([internal]): объявление было только там,
    // в проде поля создавались на лету при первом присваивании. Начальные 0 как в core.js.
    selectedMediaCount: 0,    // ставит attachment-events.js, читает toolbar.js (x-show)
    contextMenuFolderId: 0,   // ставит ContextMenu.js, читает FolderTree.js (has-context-menu)

    // [internal] (Фаза 1c): показ цвета — STUB. Реальную реализацию домёрживает
    // модуль color/ (color-entry.js ставит store._colorImpl = colorShowImpl на plathix:ready).
    // Без модуля stub возвращает ''/'none' → папки серые (семантика ВАРИАНТ А: при выносе в PRO
    // и отключении цвет пропадает с экрана, данные color в term-meta целы).
    folderColorStyle(folder) {
        return this._colorImpl ? this._colorImpl.folderColorStyle(folder) : '';
    },

    folderColorFill(folder) {
        return this._colorImpl ? this._colorImpl.folderColorFill(folder) : 'none';
    },

    _colorImpl: null,

    // [internal] ([internal]): показ trash toolbar (Restore vs
    // Move to Trash) для ТЕКУЩЕЙ открытой папки — STUB. Реальную реализацию домёрживает
    // модуль trash/ (trash-entry.js ставит store._trashImpl на plathix:ready). Без
    // модуля stub возвращает false → «Move to Trash» показывается только при
    // openId===trashFolderId (существующее поведение, безопасно по умолчанию).
    //
    // ВАЖНО (найдено на browser proof, live stand): _trashImpl.isCurrentFolderTrashed()
    // читает ОБЫЧНЫЙ (не-Alpine-реактивный) module-level Set в trash-core.js. Alpine's
    // x-show переоценивает выражение только когда effect замечает чтение РЕАКТИВНОГО
    // (proxied) свойства внутри вычисления — простой Set вне Alpine store таким не
    // является. Без чтения _trashedFolderIdsVersion ниже toolbar «застывает» на первом
    // (обычно false, до заполнения кеша) результате и не обновляется даже после того,
    // как кеш реально заполнился — кнопка показывает неверное состояние молча.
    isCurrentFolderTrashed() {
        void this._trashedFolderIdsVersion; // читаем реактивное поле — регистрирует Alpine-зависимость
        return this._trashImpl ? this._trashImpl.isCurrentFolderTrashed() : false;
    },

    _trashImpl: null,
    _trashedFolderIdsVersion: 0,

    async withLoading(fn, { rethrow = false } = {}) {
        this.isLoading = true;
        try {
            return await fn();
        } catch (error) {
            this.error = error.message;
            if (rethrow) {
                throw error;
            }
        } finally {
            this.isLoading = false;
        }
    },

    resetTransientState() {
        this.selected = [];
        this.isLoading = false;
        this.isSearching = false;
        this.searchQuery = '';
        this.error = null;
        const input = document.querySelector('.plathix-search__input');
        if (input) {
            input.value = '';
        }
    },

    cleanup() {
        if (this._searchAbort) {
            this._searchAbort.abort();
            this._searchAbort = null;
        }
        window.clearTimeout(this._searchTimer);
        window.clearTimeout(this._prefTimer);
        this._searchTimer = null;
        this._prefTimer = null;
        this.resetTransientState();
    },
};
