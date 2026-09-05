import { Events } from '../events.js';
import { t } from '../i18n.js';
import { escapeAttr } from '../utils/escape.js';

const contextMenuOverlay = () => `
    <template x-teleport="body">
    <div class="plathix-context-menu"
         x-data="contextMenu"
         x-show="isOpen"
         :style="'left:' + x + 'px;top:' + y + 'px' + (isPositioned ? '' : ';visibility:hidden')"
         @keydown.escape.window="close()"
         @${Events.CONTEXT_MENU}.window="open($event.detail)">
        <!-- Топ-слот для пунктов ПЕРЕД hr: favorites-entry монтирует
             сюда «Избранное» первым пунктом.
             Без favorites-entry — слот пустой, graceful degradation. -->
        <span class="plathix-context-menu-top" data-slot="plathix-context-menu-top" :data-folder-id="folder?.id"></span>
        <hr class="plathix-context-menu__separator" x-show="$store.plathix.canManage && !folder?.isProtected && Number(folder?.id) > 0">
        <button type="button"
                data-action="new-subfolder"
                x-show="$store.plathix.canManage && !folder?.isProtected && Number(folder?.id) > 0"
                :aria-disabled="(!$store.plathix.canCreateChild($store.plathix.getFolderDepth(folder?.id))).toString()"
                :title="$store.plathix.canCreateChild($store.plathix.getFolderDepth(folder?.id)) ? '' : ${ escapeAttr( JSON.stringify( t( 'max_depth_reached', 'Maximum nesting depth reached' ) ) ) }"
                @click="$store.plathix.canCreateChild($store.plathix.getFolderDepth(folder?.id)) ? createSubfolder() : (close(), $store.plathix.alertMessage = ${ escapeAttr( JSON.stringify( t( 'max_depth_reached', 'Maximum nesting depth reached' ) ) ) })">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            ${t('create_subfolder', 'New subfolder')}
        </button>
        <button type="button" data-action="rename" x-show="$store.plathix.canManage && !folder?.isProtected" @click="rename()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            ${t('rename_label', 'Rename')}
        </button>
        <!-- Нейтральный слот пунктов контекстного меню папки. Имя по каркасу,
             не по фиче. PRO монтирует сюда свои пункты по data-folder-id: builder
             (маркер .plathix-builder-ctx-item) и ZIP (маркер .plathix-zip-ctx-item).
             PRO-пункты упорядочены числовым data-order. Без подписчиков — пустой якорь. -->
        <span class="plathix-context-menu-items" data-slot="plathix-context-menu-items" :data-folder-id="folder?.id"></span>
        <!-- «Цвет» — пункт пикера цвета уехал в модуль resources/js/sidebar/color/:
             color-entry.js самомонтирует его в слот
             plathix-context-menu-items выше (ORDER=30 — ниже PRO Gallery/ZIP, но ВЫШЕ
             статического «Удалить»: HARD-инвариант «Цвет над Удалить» сохранён).
             Без модуля пункта цвета нет (семантика ВАРИАНТ А). «Удалить» ниже — статически. -->
        <!-- Деструктив «Удалить» — последним, визуально изолирован (sep + увеличенный gap),
             красный (plathix-context-menu__danger). Confirm-диалог уже защищает от промаха. -->
        <hr class="plathix-context-menu__separator plathix-context-menu__separator--danger" x-show="$store.plathix.canManage && !folder?.isProtected">
        <button type="button" class="plathix-context-menu__danger" x-show="$store.plathix.canManage && !folder?.isProtected" @click="remove()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            ${t('delete_label', 'Move to Trash')}
        </button>
    </div>
    </template>
`;

const notificationsOverlay = () => `
    <template x-teleport="body">
    <div class="plathix-notifications" x-show="$store.plathix.notifications.length > 0">
        <template x-for="notif in $store.plathix.notifications" :key="notif.id">
            <div class="plathix-notification" :class="'plathix-notification--' + notif.type">
                <span class="plathix-notification__dot"></span>
                <span class="plathix-notification__message" x-text="notif.message"></span>
                <button type="button" class="plathix-notification__close" @click="$store.plathix.dismissNotification(notif.id)">&#215;</button>
            </div>
        </template>
    </div>
    </template>
`;

const alertOverlay = () => `
    <template x-teleport="body">
    <div x-show="$store.plathix.alertMessage" class="plathix-alert__overlay" @click.self="$store.plathix.alertMessage = null">
        <div class="plathix-alert__box">
            <p x-text="$store.plathix.alertMessage"></p>
            <button type="button" class="button button-primary" @click="$store.plathix.alertMessage = null">OK</button>
        </div>
    </div>
    </template>
`;

const bulkDeleteOverlay = () => `
    <template x-teleport="body">
    <div x-show="$store.plathix.deletingFoldersBulk" class="plathix-alert__overlay" @click.self="$store.plathix.hideBulkDeleteConfirm()" @keydown.escape.window="$store.plathix.hideBulkDeleteConfirm()">
        <div class="plathix-delete__box">
            <p class="plathix-delete__title">${t('bulk_delete_confirm_title', 'Move the selected folders to Trash? You can restore them later.')}</p>
            <p class="plathix-delete__safe" x-show="!$store.plathix.bulkDeleteHasNested">${t('bulk_delete_confirm_safe', 'Files won\'t be deleted — depending on your Trash settings, they either move with the folders or become unassigned.')}</p>
            <div class="plathix-bulk-delete__list">
                <template x-for="folder in ($store.plathix.deletingFoldersBulk || [])" :key="folder.id">
                    <div class="plathix-delete__folder-info">
                        <span class="plathix-delete__folder-name" x-text="folder.name"></span>
                        <span class="plathix-delete__folder-count" x-show="(folder.count || 0) > 0" x-text="'(' + folder.count + ')'"></span>
                    </div>
                </template>
            </div>
            <div x-show="$store.plathix.bulkDeleteHasNested" class="plathix-delete__safe plathix-delete__safe--warning">
                ${t('bulk_delete_has_nested', 'Some selected folders contain subfolders. Choose how to handle them:')}
            </div>
            <div class="plathix-delete__actions">
                <button type="button" class="button button-primary plathix-btn--danger-folder" @click="$store.plathix.confirmDeleteSelectedFolders('delete')">
                    <span x-show="!$store.plathix.bulkDeleteHasNested">${t('delete_folders_confirm', 'Move to Trash')} (<span x-text="($store.plathix.deletingFoldersBulk || []).length"></span>)</span>
                    <span x-show="$store.plathix.bulkDeleteHasNested">${t('delete_folders_recursive', 'Move selected and all subfolders to Trash')}</span>
                </button>
                <button type="button" class="button" x-show="$store.plathix.bulkDeleteHasNested" @click="$store.plathix.confirmDeleteSelectedFolders('reattach')">
                    ${t('delete_folders_only', 'Move only selected, keep subfolders')}
                </button>
                <button type="button" class="button" @click="$store.plathix.hideBulkDeleteConfirm()">${t('cancel_label', 'Cancel')}</button>
            </div>
        </div>
    </div>
    </template>
`;

const singleDeleteOverlay = () => `
    <template x-teleport="body">
    <div x-show="$store.plathix.deletingFolder" class="plathix-alert__overlay" @click.self="$store.plathix.hideDeleteConfirm()" @keydown.escape.window="$store.plathix.hideDeleteConfirm()">
        <div class="plathix-delete__box">
            <p class="plathix-delete__title">${t('delete_confirm_title', 'Move this folder to Trash? You can restore it later.')}</p>
            <p class="plathix-delete__safe" x-show="$store.plathix.folders.some(f => Number(f.parentId) === Number($store.plathix.deletingFolder?.id))">${t('delete_confirm_choice_hint', 'Choose whether subfolders move to Trash together with this folder, or stay in place.')}</p>
            <p class="plathix-delete__safe" x-show="!$store.plathix.folders.some(f => Number(f.parentId) === Number($store.plathix.deletingFolder?.id))">${t('delete_confirm_safe', 'Files won\'t be deleted — depending on your Trash settings, they either move with the folder or become unassigned.')}</p>
            <div class="plathix-delete__folder-info" x-show="$store.plathix.deletingFolder">
                <span class="plathix-delete__folder-name" x-text="$store.plathix.deletingFolder?.name"></span>
                <span class="plathix-delete__folder-count" x-show="($store.plathix.deletingFolder?.count || 0) > 0" x-text="'(' + $store.plathix.deletingFolder?.count + ')'"></span>
            </div>
            <div class="plathix-delete__actions">
                <button type="button" class="button button-primary plathix-btn--danger-folder" @click="$store.plathix.deleteFolder(Number($store.plathix.deletingFolder?.id), 'delete')">${t('delete_folder_recursive', 'Move folder and subfolders to Trash')}</button>
                <button type="button" class="button button-primary"
                        x-show="$store.plathix.folders.some(f => Number(f.parentId) === Number($store.plathix.deletingFolder?.id))"
                        @click="$store.plathix.deleteFolder(Number($store.plathix.deletingFolder?.id), 'reattach')">${t('delete_folder_only', 'Move folder to Trash')}</button>
                <button type="button" class="button" @click="$store.plathix.hideDeleteConfirm()">${t('cancel_label', 'Cancel')}</button>
            </div>
        </div>
    </div>
    </template>
`;

// Overlays корзины (mediaTrash/mediaRestore) уехали в trash-entry.js ([internal]):
// trash-entry монтирует их самостоятельно через data-slot="plathix-trash-overlay".
// Платформа предоставляет нейтральный слот; без trash-entry — слот пустой, без фатала.
const trashOverlaySlot = () => `<div data-slot="plathix-trash-overlay"></div>`;

export const overlaysTemplate = () => [
    contextMenuOverlay(),
    notificationsOverlay(),
    alertOverlay(),
    bulkDeleteOverlay(),
    singleDeleteOverlay(),
    trashOverlaySlot(),
    // Overlay билдера рендерит PRO-бандл shortcode-builder.js (в body), не sidebar ([internal]).
    // Overlay folder-upload — тоже: рендерит PRO-бандл folder-upload в body ([internal]),
    // не sidebar. Без PRO прогресс-оверлея загрузки папок нет вовсе.
].join('\n\n');
