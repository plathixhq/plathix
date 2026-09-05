const DEFAULT_COLOR = '#2271b1';
const HEX6 = /^[0-9a-f]{6}$/;

/**
 * Alpine-компонент выбора цвета папки (контекстное меню) — [internal] (форма v4).
 *
 * ОТЛИЧИЕ от прежнего colorPickerComponent (components/ColorPicker.js, удаляется): источник
 * текущей папки — НЕ scope контекст-меню (`folder`), а STORE (`$store.plathix.contextMenuFolderId`).
 * Это позволяет разметке пикора жить в самомонтируемом модуле color/, не завися от scope
 * компонента contextMenu (paradigm-skeptic v4 killer X2: самомонтируемый узел scope не видит,
 * но store — глобальный синглтон, доступен отовсюду).
 *
 * - `currentColor` (getter) — реактивно читает цвет ТЕКУЩЕЙ папки из store по contextMenuFolderId.
 *   x-effect по нему пересчитывается при смене папки (open() переставляет contextMenuFolderId)
 *   И при перекраске (setFolderColor→refreshFolders присваивает новый folders — X4).
 * - `set(value)` — нормализует и пишет через store.setFolderColor(contextMenuFolderId, ...).
 * - `hasColor` — для `--empty`-плейсхолдера (папка без цвета).
 */
export function colorPickerComponent() {
    return {
        // ЛОКАЛЬНОЕ реактивное поле превью (как в до-атомизационной рабочей версии). Input
        // байндится на него (`:value="color"`), а `x-effect` синхронно тянет его из store при
        // открытии меню — это форсит перерисовку нативного <input type=color> в МОМЕНТ показа.
        // Прямой getter-биндинг в store (форма v4) в реальном браузере кружок не перерисовывал
        // мгновенно (баг «красится только после ребута»); локальное поле + x-effect это чинит —
        // ровно так работало до атомизации, отпилено было зря ([internal]).
        color: DEFAULT_COLOR,

        get _folderId() {
            return Number(this.$store.plathix.contextMenuFolderId) || 0;
        },

        get _folder() {
            const id = this._folderId;
            return id > 0 ? this.$store.plathix.folders.find((f) => Number(f.id) === id) : null;
        },

        /** Есть ли у текущей папки заданный цвет (для placeholder-«+» vs swatch). */
        get hasColor() {
            return !!this._folder?.color;
        },

        /**
         * Синхронизация локального `color` с цветом текущей папки из store. Зовётся из
         * `x-effect` при открытии/смене папки (contextMenuFolderId реактивен → эффект
         * пере-выполняется). Пустой/невалидный цвет папки → дефолт для нативного пикера.
         */
        syncFromStore() {
            // id=0 — sentinel «меню закрыто» (close() в ContextMenu.js ставит contextMenuFolderId=0).
            // НЕ сбрасывать превью на дефолт при закрытии: держим последний показанный цвет до
            // реального открытия на новой папке. ([internal] R2: @change больше НЕ обнуляет id —
            // это гасило hasColor→:style прямо в тик выбора; теперь обнуляет только close().)
            if (this._folderId === 0) {
                return;
            }
            const c = this._folder?.color;
            this.color = HEX6.test(String(c || '').replace(/^#/, '').toLowerCase()) ? c : DEFAULT_COLOR;
        },

        /**
         * Нормализатор от нативного пикера: срезает `#`, lowercase; если ровно 6 hex — пишем в
         * локальное превью (мгновенно) И в store по id текущей папки (сохранение). Пустое/
         * невалидное — игнор.
         */
        set(value) {
            const hex = String(value || '').trim().replace(/^#/, '').toLowerCase();
            const id = this._folderId;
            if (HEX6.test(hex) && id > 0) {
                this.color = '#' + hex;
                this.$store.plathix.setFolderColor(id, '#' + hex);
            }
        },
    };
}
