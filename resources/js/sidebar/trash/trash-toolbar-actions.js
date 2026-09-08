import { t } from '../i18n.js';

export const ACTION_MARKER = 'plathix-trash-action-item';

export function trashActionsHTML() {
    return `<div class="plathix-system__action-btns ${ACTION_MARKER}"
                 x-show="window.Plathix?.postType === 'attachment' && $store.plathix.canAssign && !$store.plathix.folderSelectMode && $store.plathix.selectedMediaCount > 0"
                 x-cloak>
        <button type="button"
                class="plathix-system__action-btn is-active"
                :disabled="$store.plathix.mediaTrashPending"
                x-show="Number($store.plathix.openId) !== Number(window.Plathix?.trashFolderId || 0) && !$store.plathix.isCurrentFolderTrashed()"
                @click="$store.plathix.showMediaTrashConfirm()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            ${t('move_to_trash', 'Move to Trash')}
        </button>
        <button type="button"
                class="plathix-system__action-btn is-active"
                :disabled="$store.plathix.mediaRestorePending"
                x-show="Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed()"
                @click="$store.plathix.restoreMedia()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
            ${t('restore_label', 'Restore')}
        </button>
    </div>`;
}
