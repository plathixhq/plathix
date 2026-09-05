import { t } from '../i18n.js';
import { escapeAttr } from '../utils/escape.js';
import { Events } from '../events.js';

const toolbarExtraButtons = () => {
    const items = window.Plathix?.toolbarExtra;
    if (!Array.isArray(items) || !items.length) return '';
    return items.map(({ id, title, icon, active }) => `
        <button type="button"
                class="plathix-tool__btn"
                ${active ? `:class="{ 'is-active': $store.plathix['${active}'] }"` : ''}
                ${active ? `:aria-pressed="String(!!$store.plathix['${active}'])"` : ''}
                title="${title}"
                @click="window.dispatchEvent(new CustomEvent('${Events.TOOLBAR_ACTION}', { detail: { id: '${id}' } }))">
            ${icon}
        </button>
    `).join('');
};

export const toolbarTemplate = () => `
    <div class="plathix-system__block">
        <div class="plathix-actions">
            <span class="plathix-section__title">${window.Plathix?.postTypeLabel || ''}</span>
            <div class="plathix-toolbar" x-show="$store.plathix.canManage">
                <button type="button"
                        class="plathix-tool__btn"
                        :disabled="$store.plathix.folders.some(f => Number(f.id) === Number($store.plathix.openId) && f.isProtected && Number(f.id) > 0)"
                        :title="${ escapeAttr( JSON.stringify( t( 'add_folder', '+ Folder' ) ) ) }"
                        @click="createRootFolder()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
                <button type="button"
                        class="plathix-tool__btn"
                        :class="{ 'is-active': $store.plathix.folderSelectMode }"
                        :aria-pressed="String($store.plathix.folderSelectMode)"
                        title="${t('select_folders', 'Select folders')}"
                        @click="$store.plathix.toggleFolderSelectMode()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
                </button>
	                <button type="button"
	                        class="plathix-tool__btn"
	                        title="${t('toggle_expand_all', 'Expand/collapse all')}"
	                        @click="$store.plathix.toggleExpandAllLoaded()">
	                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 8 12 3 17 8"/><line x1="12" y1="3" x2="12" y2="15"/><polyline points="7 16 12 21 17 16"/></svg>
	                </button>
                <button type="button"
                        class="plathix-tool__btn"
                        :class="{ 'is-active': $store.plathix.folderDragMode }"
                        :aria-pressed="String($store.plathix.folderDragMode)"
                        title="${t('drag_mode', 'Drag mode')}"
                        @click="$store.plathix.toggleFolderDragMode()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>
                </button>
                <!-- Все опциональные кнопки (Shortcode builder, Folder sizes, Upload folder)
                     монтируются через ЕДИНЫЙ нейтральный слот toolbarExtra: каждый
                     модуль-владелец кладёт дескриптор с числовым order через фильтр
                     plathix/sidebar_toolbar_extra, Free сортирует по order и рендерит их в
                     toolbarExtraButtons() ниже; клик шлёт generic TOOLBAR_ACTION с id, бандл
                     фичи (PRO builder+folder-upload+folder-info) его ловит.
                     Порядок детерминирован: builder(10) → sizes(20) → upload(30). Free-шаблон
                     про конкретные фичи не знает. -->
                ${toolbarExtraButtons()}
            </div>
        </div>
        <div data-slot="plathix-search"></div>

        <div class="plathix-system__folders" x-data="folderTree" x-init="parentId = 0">
            <template x-for="folder in $store.plathix.systemRootFolders" :key="folder.id">
                <div class="plathix-folder-branch">
                    ${systemFolderItem()}
                    <template x-if="hasChildren(folder.id) && !$store.plathix.isCollapsed(folder.id)">
                        <div class="plathix-folder__children"
                             x-data="folderTree"
                             x-init="parentId = Number(folder.id)"
                             x-html="treeLevelHtml()"></div>
                    </template>
                </div>
            </template>

            <!-- Кнопки корзины (Move to Trash / Restore) уехали в trash-entry.js:
                 trash-entry самомонтирует их через этот слот.
                 Без trash-entry — слот пустой, без фатала. -->
            <div data-slot="plathix-trash-actions"></div>
        </div>
    </div>
`;

const systemFolderItem = () => `
    <div class="plathix-folder"
         :data-folder-id="folder.id"
         tabindex="0"
         role="button"
         :class="folderClasses(folder)"
         @click="handleFolderClick(folder)"
         @keydown.enter.prevent="handleFolderClick(folder)"
         @keydown.space.prevent="handleFolderClick(folder)"
         @contextmenu.prevent="handleContextMenu(folder, $event)">
        <template x-if="Number(folder.id) === Number(window.Plathix?.trashFolderId || 0)">
            <svg class="plathix-folder__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
        </template>
        <template x-if="Number(folder.id) !== Number(window.Plathix?.trashFolderId || 0)">
            <svg class="plathix-folder__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
        </template>
        <span class="plathix-folder__name" x-text="folder.name"></span>
        <!-- Trash-узел (foldersCount != null): ЧЁТКО файлы и папки раздельно — иконка файла +
             число / иконка папки + число (заменяет буквы Ф/П). Иконки с
             aria-label (доступность). Прочие системные папки — обычный .count. -->
        <span class="plathix-trash-counts" x-show="folder.foldersCount !== null && folder.foldersCount !== undefined">
            <svg class="plathix-trash-counts__icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="${t('trash_files_label', 'Files')}"><title>${t('trash_files_label', 'Files')}</title><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span x-text="folder.count || 0"></span>
            <span class="plathix-trash-counts__separator">/</span>
            <svg class="plathix-trash-counts__icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="${t('trash_folders_label', 'Folders')}"><title>${t('trash_folders_label', 'Folders')}</title><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            <span x-text="folder.foldersCount || 0"></span>
        </span>
        <span class="count" x-text="folder.count" x-show="folder.count > 0 && (folder.foldersCount === null || folder.foldersCount === undefined)"></span>
    </div>
`;
