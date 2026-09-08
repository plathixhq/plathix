import { t } from '../i18n.js';
import { newFolderForm, folderItem } from './folder-tree.js';

const emptyStateContent = () =>
    window.Plathix?.emptyState ||
    `<p>${t('no_folders_yet', 'No folders yet. Create your first folder to organize files.')}</p>`;

export const userTreeTemplate = () => `
    <div class="plathix-user__block">
        <div class="plathix-folder__select-bar" x-show="$store.plathix.folderSelectMode">
            <button type="button"
                    class="plathix-btn--danger-folder button"
                    :disabled="$store.plathix.selectedFolderIds.length === 0"
                    @click="$store.plathix.showBulkDeleteConfirm()">
                ${t('delete_folders', 'Move to Trash')} (<span x-text="$store.plathix.selectedFolderIds.length"></span>)
            </button>
            <button type="button" class="button" @click="$store.plathix.toggleFolderSelectMode()">${t('cancel_label', 'Cancel')}</button>
        </div>
        <div class="plathix-search__only-hint" x-show="$store.plathix.isSearchOnlyMode">
            <p>${t('search_to_browse', 'Type to browse folders')}</p>
        </div>

        <div class="plathix-tree" x-data="folderTree" x-init="parentId = 0" x-show="!$store.plathix.isSearchOnlyMode || $store.plathix.searchQuery">
            <template x-for="folder in folders.filter(f => !f.isProtected)" :key="folder.id">
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

            <div class="plathix-empty-state" x-show="!$store.plathix.isSearching && $store.plathix.hasNoUserFolders && !$store.plathix.searchQuery">
                ${emptyStateContent()}
            </div>

            <div class="plathix-no-results" x-show="!$store.plathix.isSearching && $store.plathix.hasNoSearchResults">
                <p>${t('no_folders_found', 'No folders found.')}</p>
                <button type="button" @click="$store.plathix.clearSearch()">${t('clear_search', 'Clear search')}</button>
            </div>
        </div>
    </div>
`;
