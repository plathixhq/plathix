import { t } from '../i18n.js';
import { escapeAttr } from '../utils/escape.js';

export const OVERLAY_MARKER = 'plathix-trash-overlay-item';

/**
 * [internal]: bulk-selection чекбоксов в Trash-гриде доступен на любой ширине экрана
 * (часть core WP media grid, не зависит от нашего сайдбар-брейкпоинта), но кнопка
 * «Восстановить» из trashActionsHTML() смонтирована ТОЛЬКО внутри сайдбара
 * (`.media-menu.plathix-mounted`), который целиком скрывается на <480px
 * (sidebar.css, @media min-width:480px, [internal]/#265). Возникает asymmetry: можно
 * начать bulk-выбор, но нечем его явно завершить на узком экране (row-action —
 * per-file, drag-and-drop нескольких файлов на touch неосуществим).
 * Третий teleport-блок ниже — дубль той же Restore-кнопки (тот же restoreMedia(),
 * тот же mediaRestorePending-guard), видимый ТОЛЬКО на <480px через CSS
 * (.plathix-mobile__bulk-restore, sidebar.css) — не расширяет сайдбар-брейкпоинт,
 * не трогает существующую sidebar-кнопку.
 */
export function trashOverlaysHTML() {
    return `<div class="${OVERLAY_MARKER}">
    <template x-teleport="body">
    <div x-show="$store.plathix.mediaTrashConfirm" class="plathix-alert__overlay"
         @click.self="$store.plathix.hideMediaTrashConfirm()"
         @keydown.escape.window="$store.plathix.hideMediaTrashConfirm()">
        <div class="plathix-delete__box">
            <p class="plathix-delete__title">
                <span x-text="($store.plathix.mediaTrashConfirm || []).length + ' ' + ${ escapeAttr( JSON.stringify( t( 'files_selected', 'files selected' ) ) ) }"></span>
            </p>
            <p class="plathix-delete__safe">
                ${t('trash_confirm_hint', 'Files will be moved to trash and can be restored from there.')}
            </p>
            <div class="plathix-delete__actions">
                <button type="button" class="button button-primary" @click="$store.plathix.confirmMediaTrash()">
                    ${t('move_to_trash', 'Move to Trash')}
                </button>
                <button type="button" class="button" @click="$store.plathix.hideMediaTrashConfirm()">
                    ${t('cancel_label', 'Cancel')}
                </button>
            </div>
        </div>
    </div>
    </template>
    <template x-teleport="body">
    <div x-show="Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) && $store.plathix.mediaRestorePending"
         class="plathix-alert__overlay">
        <div class="plathix-alert__box">
            <p>${t('restore_label', 'Restore')}...</p>
        </div>
    </div>
    </template>
    <template x-teleport="body">
    <button type="button"
            class="plathix-mobile__bulk-restore"
            :disabled="$store.plathix.mediaRestorePending"
            x-show="$store.plathix.selectedMediaCount > 0 && (Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed())"
            x-cloak
            @click="$store.plathix.restoreMedia()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
        ${t('restore_label', 'Restore')}
    </button>
    </template>
    </div>`;
}
