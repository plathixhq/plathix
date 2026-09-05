import { Events } from '../events.js';
import { t } from '../i18n.js';
import { treeLevelMarkup } from '../templates/folder-tree.js';
import { confirmAndMoveItems } from '../dnd.js';

export function folderTree() {
    return {
        parentId: 0,
        isDragging: false,
        dragOverId: null,

        // [internal]: разметка ОДНОГО уровня для рекурсивного x-html.
        // Вложенный контейнер `.plathix-folder__children` — это компонент folderTree со своим
        // parentId; его `x-html="treeLevelHtml()"` вставляет один уровень при инстанцировании
        // (Alpine инициализирует директивы фрагмента), давая рантайм-рекурсию без пре-генерации
        // строки на всю глубину. Материализуется только под истинным x-if (есть дети, не свёрнуто).
        treeLevelHtml() {
            return treeLevelMarkup();
        },

        // [internal] (Фаза 0): показ цвета делегируется в store — единый канал показа
        // (как favorites.js через $store). Реальная реализация в store (ui-state.js), которую
        // в Фазе 1 заберёт модуль color/ через stub-домёрж. Локальной копии больше нет.
        folderColorStyle(folder) {
            return this.$store.plathix.folderColorStyle(folder);
        },

        folderColorFill(folder) {
            return this.$store.plathix.folderColorFill(folder);
        },

        // --- helpers extracted from inline template expressions ---

        isNewFormHere() {
            const s = this.$store.plathix;
            return s.newFolderParentId !== null && Number(s.newFolderParentId) === Number(this.parentId);
        },

        handleFolderClick(folder) {
            const s = this.$store.plathix;
            if (s.folderSelectMode && !folder.isProtected) {
                s.toggleFolderSelected(folder.id);
            } else {
                s.openFolder(folder.id);
            }
        },

        handleContextMenu(folder, event) {
            const s = this.$store.plathix;
            if (s.folderSelectMode || window.Plathix?.isTouch) return;
            const folderEl = event.target.closest('.plathix-folder');
            this.$dispatch(Events.CONTEXT_MENU, { folder, event, folderEl });
        },

        folderClasses(folder) {
            const s = this.$store.plathix;
            return {
                'is-open': Number(s.openId) === Number(folder.id),
                'is-drag-over': this.dragOverId === folder.id,
                'is-selected': s.folderSelectMode && s.isFolderSelected(folder.id),
                // contextMenuFolderId === 0 — sentinel «меню закрыто» (ui-state.js), НЕ реальная папка:
                // требуем > 0, иначе системная папка «Медиафайлы» (id=0) вечно липла бы has-context-menu
                // (тот же CSS-фон, что is-open → две подсветки). См. [internal].
                'has-context-menu': Number(s.contextMenuFolderId) > 0 && Number(s.contextMenuFolderId) === Number(folder.id),
            };
        },

        showCollapsePlaceholder(folder) {
            const s = this.$store.plathix;
            return !(this.hasChildren(folder.id) && !s.folderSelectMode && !s.folderDragMode)
                && !(s.folderSelectMode && !folder.isProtected)
                && !(s.folderDragMode && !folder.isProtected && s.canManage);
        },

        folderDraggable(folder) {
            const s = this.$store.plathix;
            return !folder.isProtected && s.canManage && !s.folderSelectMode ? 'true' : 'false';
        },

        hasChildrenOrNewForm(folderId) {
            return this.hasChildren(folderId) || this.$store.plathix.newFolderParentId === folderId;
        },

        // --- existing methods ---

        handleFolderDragStart(event, folder) {
            if (folder.isProtected || !this.$store.plathix.canManage) {
                event.preventDefault();
                return;
            }
            this.$store.plathix.isFolderDragging = true;
            this.$store.plathix.folderDragId = folder.id;
            event.dataTransfer.setData(Events.DRAG_FOLDER_MIME, String(folder.id));
            event.dataTransfer.effectAllowed = 'move';
            document.body.classList.add('plathix-internal-drag');
        },

        handleFolderDragEnd() {
            this.$store.plathix.isFolderDragging = false;
            this.$store.plathix.folderDragId = null;
            this.$store.plathix.folderDropId = null;
            this.$store.plathix.folderDropPos = null;
            document.body.classList.remove('plathix-internal-drag');
        },

        createSubfolder(parentId) {
            this.$store.plathix.showNewFolderForm(Number(parentId) || 0);
        },

        get folders() {
            const raw = this.$store.plathix._childrenByParent.get(Number(this.parentId ?? 0)) ?? [];
            const sortBy = this.$store.plathix.sortBy;
            if (!sortBy || sortBy === 'default') return raw;
            const arr = [...raw];
            if (sortBy === 'alpha')   return arr.sort((a, b) => String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' }));
            if (sortBy === 'alpha_z') return arr.sort((a, b) => String(b.name).localeCompare(String(a.name), undefined, { sensitivity: 'base' }));
            if (sortBy === 'new')     return arr.sort((a, b) => Number(b.id) - Number(a.id));
            if (sortBy === 'old')     return arr.sort((a, b) => Number(a.id) - Number(b.id));
            if (sortBy === 'size')    return arr.sort((a, b) => Number(b.count ?? 0) - Number(a.count ?? 0));
            return raw;
        },

        hasChildren(folderId) {
            if (Number(folderId) <= 0) return false;
            return this.$store.plathix._hasChildrenSet.has(Number(folderId));
        },

        async handleCollapseToggle(folder) {
            const store = this.$store.plathix;
            const folderId = Number(folder?.id) || 0;
            if (folderId <= 0) return;
            const isCollapsed = store.isCollapsed(folderId);
            if (isCollapsed && !store.hasLoadedChildren(folderId)) {
                await store.loadFolderChildren(folderId, { silent: true });
            }
            store.toggleCollapse(folderId);
        },

        handleDrop(event, targetFolderId) {
            event.preventDefault();
            event.stopImmediatePropagation();

            const types = Array.from(event.dataTransfer?.types || []);
            const isExternalUpload = types.includes('Files');
            const isInternalMove = types.includes(Events.DRAG_ITEMS_MIME);
            if (isExternalUpload && !isInternalMove) {
                return;
            }

            const raw = event.dataTransfer?.getData(Events.DRAG_ITEMS_MIME) || '[]';
            let itemIds = [];

            try {
                itemIds = JSON.parse(raw);
            } catch (error) {
                itemIds = [];
            }

            if (!Array.isArray(itemIds) || !itemIds.length) {
                return;
            }

            // [internal]: раньше вызывал moveItemsBulk напрямую, обходя trash-restore
            // confirm ([internal]) и bulk-safe confirm — единственный source of truth для
            // confirm-правил теперь dnd.js::confirmAndMoveItems ([internal]).
            confirmAndMoveItems(itemIds, targetFolderId, event.currentTarget);
            this.isDragging = false;
            this.dragOverId = null;
        },

        async createFolder(parentId = 0) {
            const name = window.prompt(t('new_folder_name', 'Folder name:'));
            if (!name || !name.trim()) {
                return;
            }

            await this.$store.plathix.createFolder(name.trim(), Number(parentId) || 0);
        },

        renameFolder(folder) {
            if (!folder || folder.isProtected) return;
            this.$store.plathix.showRenameForm(folder);
        },

        deleteFolder(folder) {
            if (!folder || folder.isProtected) return;
            this.$store.plathix.showDeleteConfirm(folder);
        },
    };
}
