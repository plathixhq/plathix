export function contextMenuComponent() {
    return {
        isOpen: false,
        isPositioned: false,
        folder: null,
        x: 0,
        y: 0,

        init() {
            // Close only on LEFT click outside the menu.
            // Right-click (button=2) must be ignored — the mousedown from a contextmenu event
            // would otherwise close the menu before the new right-click can open it.
            this._outsideHandler = (e) => {
                if (!this.isOpen || e.button !== 0) return;
                if (this.$el && this.$el.contains(e.target)) return;
                this.close();
            };
            document.addEventListener('mousedown', this._outsideHandler);
            // Нейтральный event-контракт для закрытия меню из слот-модулей ([internal]).
            this._ctxCloseHandler = () => this.close();
            window.addEventListener('plathix:ctx-close', this._ctxCloseHandler);
        },

        destroy() {
            document.removeEventListener('mousedown', this._outsideHandler);
            window.removeEventListener('plathix:ctx-close', this._ctxCloseHandler);
        },

        open(payload) {
            if (payload.folder?.isProtected) return;
            this.folder = payload.folder;
            this.$store.plathix.contextMenuFolderId = Number(payload.folder?.id || 0);

            const folderEl = payload.folderEl || payload.event.target.closest('.plathix-folder');
            if (folderEl) {
                const fr = folderEl.getBoundingClientRect();
                this.x = fr.right;
                this.y = fr.bottom + 5;
            } else {
                this.x = payload.event.clientX;
                this.y = payload.event.clientY;
            }

            this.isPositioned = false;
            this.isOpen = true;
            this.$nextTick(() => {
                if (!this.$el) return;
                const rect = this.$el.getBoundingClientRect();
                const vh = window.innerHeight;
                const sidebarEl = document.getElementById('plathix-sidebar-root');
                const sidebarRight = sidebarEl
                    ? sidebarEl.getBoundingClientRect().right
                    : window.innerWidth;
                if (rect.bottom > vh - 8) {
                    this.y = Math.max(8, this.y - rect.height - (folderEl ? folderEl.getBoundingClientRect().height + 10 : 0));
                }
                // выравниваем правый край меню по правому краю папки
                this.x = Math.max(8, (folderEl ? folderEl.getBoundingClientRect().right : this.x) - rect.width);
                this.isPositioned = true;
            });
        },

        close() {
            this.isOpen = false;
            this.isPositioned = false;
            this.$store.plathix.contextMenuFolderId = 0;
        },

        createSubfolder() {
            if (!this.folder) return;
            this.$store.plathix.showNewFolderForm(Number(this.folder.id));
            this.close();
        },

        rename() {
            if (!this.folder) return;
            this.$store.plathix.showRenameForm(this.folder);
            this.close();
        },

        remove() {
            if (!this.folder) return;
            this.$store.plathix.showDeleteConfirm(this.folder);
            this.close();
        },

        // openShortcodeBuilder УДАЛЁН ([internal]): пункт билдера в контекстном меню теперь
        // монтирует PRO через нейтральный слот plathix-context-menu-items ([internal]).
        // Free-контекстменю про билдер не знает.

        // downloadZip УДАЛЁН ([internal]): пункт «Download ZIP» контекстного меню теперь
        // монтирует PRO через нейтральный слот plathix-context-menu-items (маркер
        // .plathix-zip-ctx-item, свой делегированный клик). Free-контекстменю про zip не знает.

        // toggleFavorite УДАЛЁН ([internal]): пункт «Избранное» теперь монтирует
        // favorites-entry.js через нейтральный слот plathix-context-menu-items (ORDER=5).
        // Закрытие меню через event plathix:ctx-close (слушается в init/destroy).
    };
}
