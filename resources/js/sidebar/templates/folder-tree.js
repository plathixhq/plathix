import { Events } from '../events.js';
import { t } from '../i18n.js';

export const newFolderForm = () => `
    <div class="plathix-new-folder__form"
         role="dialog"
         aria-modal="true"
         aria-label="${t('create_folder', 'Create folder')}"
         x-show="isNewFormHere()"
         x-effect="if (isNewFormHere() && !$el.contains(document.activeElement)) $store.plathix.focusNewFolderInput()"
         @keydown.escape.window="$store.plathix.hideNewFolderForm()"
         @keydown.enter.prevent="$store.plathix.submitNewFolder()">
        <svg class="plathix-folder__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
        </svg>
        <input type="text"
               class="plathix-new-folder__input"
               x-model="$store.plathix.newFolderName"
               placeholder="${t('new_folder_name', 'Folder name...')}">
    </div>
`;

export const folderItem = () => `
                <template x-if="$store.plathix.renamingFolderId === folder.id">
                    <div class="plathix-new-folder__form plathix-rename__form"
                         role="dialog"
                         aria-modal="true"
                         aria-label="${t('rename_folder', 'Rename folder')}"
                         x-effect="if (!$el.contains(document.activeElement)) $store.plathix.focusRenameInput()"
                         @keydown.escape.stop="$store.plathix.hideRenameForm()"
                         @keydown.enter.prevent.stop="$store.plathix.submitRename()">
                        <svg class="plathix-folder__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path :fill="folderColorFill(folder)" d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                        </svg>
                        <input type="text"
                               class="plathix-new-folder__input"
                               x-model="$store.plathix.renamingFolderName">
                    </div>
                </template>
                <template x-if="$store.plathix.renamingFolderId !== folder.id">
                    <div class="plathix-folder"
                         :data-folder-id="folder.id"
                         tabindex="0"
                         role="button"
                         :draggable="folderDraggable(folder)"
                         :class="folderClasses(folder)"
                         @click="handleFolderClick(folder)"
                         @keydown.enter.prevent="handleFolderClick(folder)"
                         @keydown.space.prevent="handleFolderClick(folder)"
                         @contextmenu.prevent="handleContextMenu(folder, $event)"
                         @dragstart="handleFolderDragStart($event, folder)"
                         @dragend="handleFolderDragEnd($event)"
                         @dragover.prevent="dragOverId = folder.id"
                         @dragleave="dragOverId = null"
                         @drop="handleDrop($event, folder.id)">
                        <button type="button" class="plathix-select__dot"
                              role="checkbox"
                              aria-label="${t('select_folder', 'Select folder')}"
                              :aria-checked="$store.plathix.isFolderSelected(folder.id)"
                              x-show="$store.plathix.folderSelectMode && !folder.isProtected"
                              :class="{ 'is-checked': $store.plathix.isFolderSelected(folder.id) }"
                              @click.stop="$store.plathix.toggleFolderSelected(folder.id)"></button>
                        <span class="plathix-drag__handle"
                              x-show="$store.plathix.folderDragMode && !folder.isProtected && $store.plathix.canManage && !$store.plathix.folderSelectMode">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>
                        </span>
	                        <button type="button"
	                                class="plathix-collapse__btn"
	                                x-show="hasChildren(folder.id) && !$store.plathix.folderSelectMode && !$store.plathix.folderDragMode"
	                                :class="{ 'is-collapsed': $store.plathix.isCollapsed(folder.id) }"
	                                @click.stop="handleCollapseToggle(folder)"
	                                :aria-label="$store.plathix.isCollapsed(folder.id) ? 'Развернуть' : 'Свернуть'">
	                        </button>
                        <span x-show="showCollapsePlaceholder(folder)" class="plathix-collapse__placeholder"></span>
                        <svg class="plathix-folder__icon"
                             :style="folderColorStyle(folder)"
                             width="16" height="16" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor"
                             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <g x-show="!hasChildren(folder.id) || $store.plathix.isCollapsed(folder.id)">
                                <path :fill="folderColorFill(folder)" d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                            </g>
                            <g x-show="hasChildren(folder.id) && !$store.plathix.isCollapsed(folder.id)">
                                <path :fill="folderColorFill(folder)" d="M22 11V8a2 2 0 00-2-2h-9l-2-3H4a2 2 0 00-2 2v14M2 19l3.4-7.3A1 1 0 016.3 11H23l-3.4 7.3A1 1 0 0118.7 19z"/>
                            </g>
                        </svg>
                        <span class="plathix-folder__name" x-text="folder.name"></span>
                        <span class="plathix-favorite__star" x-show="!folder.isProtected && $store.plathix.isFavorite(folder.id)" aria-hidden="true">★</span>
                        <!-- Нейтральный per-folder слот для сторонних действий. Имя по
                             каркасу (folder-row-actions), НЕ по фиче. Сайдбар про билдер не знает;
                             PRO по data-folder-id монтирует свою edit-иконку. Без
                             подписчика — пустой якорь. -->
                        <span class="plathix-folder-row-actions" data-slot="plathix-folder-row-actions" :data-folder-id="folder.id"></span>
                        <span class="count" x-text="folder.count" x-show="folder.count > 0"></span>
                        <button type="button" class="plathix-folder__dots" x-show="!$store.plathix.folderSelectMode && !$store.plathix.folderDragMode" @click.stop="handleContextMenu(folder, $event)" aria-label="Actions">
                            <svg width="13" height="13" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.6" fill="currentColor"/><circle cx="10" cy="10" r="1.6" fill="currentColor"/><circle cx="10" cy="16" r="1.6" fill="currentColor"/></svg>
                        </button>
                        <span class="plathix-folder__info"
                              x-show="window.Plathix?.features?.folderInfo && $store.plathix.showFolderInfo && !folder.isProtected"
                              x-html="$store.plathix.folderInfoLine(folder)"></span>
                    </div>
                </template>
`;

// [internal]: разметка ОДНОГО уровня дерева (без пре-генерации глубины).
//
// Раньше treeLevel(parent, depth) рекурсивно СКЛЕИВАЛ строку на TEMPLATE_MAX_DEPTH=30
// уровней матрёшкой ещё до Alpine → 30 пустых уровней разметки на КАЖДУЮ папку → десятки МБ
// DOM даже на плоском дереве (заметр: 88 плоских папок = 23МБ, 196КБ/листовая ветка).
//
// Теперь: генерируется ровно ОДИН уровень. Место детей ветки — контейнер
// `.plathix-folder__children`, который сам является компонентом `folderTree` и рендерит
// следующий уровень РАНТАЙМОМ через `x-html="treeLevelHtml()"` (см. FolderTree.js). Alpine 3.x
// инициализирует директивы во вставленном x-html-фрагменте, поэтому рекурсия происходит при
// инстанцировании, и ТОЛЬКО когда `x-if` истинно (есть дети и не свёрнуто). Строка конечна,
// глубина DOM = фактической глубине раскрытых данных, потолка нет (unlimited соблюдён).
//
// Экспортируется как единый узел для трёх call-site: folder-tree (вложенные дети),
// user-tree.js (юзер-корень), toolbar.js (системный корень использует свой systemFolderItem,
// но детей рендерит этим же узлом).
export const treeLevelMarkup = () => `
    <template x-for="folder in folders" :key="folder.id">
        <div class="plathix-folder-branch">
            ${folderItem()}
            <template x-if="hasChildrenOrNewForm(folder.id) && !$store.plathix.isCollapsed(folder.id)">
                <div class="plathix-folder__children"
                     x-data="folderTree"
                     x-init="parentId = Number(folder.id)"
                     x-html="treeLevelHtml()"></div>
            </template>
        </div>
    </template>
    ${newFolderForm()}
`;

// Обёртка одного уровня в собственный `folderTree`-scope (для корневого использования и
// внутренней рекурсии через x-html). parentExpr — выражение id родителя в текущем Alpine-scope.
export const treeLevel = (parentExpr = '0') => `
    <div class="plathix-tree-level" x-data="folderTree" x-init="parentId = Number(${parentExpr})">
        ${treeLevelMarkup()}
    </div>
`;
