import { t } from '../i18n.js';
import { confirmAndMoveItems } from '../dnd.js';

export function bulkActionsComponent() {
    return {
        createRootFolder() {
            const openId = Number(this.$store.plathix.openId) || 0;
            const isSystemFolder = openId <= 0
                || openId === Number(this.$store.plathix.FOLDER_UNCATEGORIZED);
            const parentId = isSystemFolder ? 0 : openId;
            // [internal] ([internal]): верхняя «+» больше НЕ toggle. Повторный клик по той
            // же «+» при уже открытой форме этого же контекста не скрывает форму (это ломало
            // фокус при быстром долблении), а перефокусирует существующий input. Если открыта
            // форма ДРУГОГО контекста (подпапка) — это смена контекста, переоткрываем.
            const current = this.$store.plathix.newFolderParentId;
            if (current !== null && Number(current) === Number(parentId)) {
                this.$store.plathix.focusNewFolderInput();
                return;
            }
            this.$store.plathix.showNewFolderForm(parentId);
        },

        moveSelected(folderId) {
            const ids = this.$store.plathix.getSelectedItemIds();
            if (!ids.length) {
                this.$store.plathix.error = t('no_items_selected', 'No items selected.');
                return;
            }

            const targetFolderId = Number(folderId) || 0;
            if (targetFolderId <= 0) {
                this.$store.plathix.error = t('invalid_move_target', 'Open a destination folder first.');
                return;
            }

            confirmAndMoveItems(ids, targetFolderId, null);
        },
    };
}
