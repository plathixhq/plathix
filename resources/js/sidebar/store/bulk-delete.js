import { Api, DEFAULT_ON_CHILDREN } from '../api.js';
import { t } from '../i18n.js';
import { getRuntime } from '../runtime.js';
import { isInDeletedSubtree, findReattachTarget } from './folder-tree-utils.js';
import { memClear } from '../media-grid-cache.js';
import { Events } from '../events.js';

export const bulkDeleteModule = {
    deletingFoldersBulk: null,
    bulkDeleteHasNested: false,

    showBulkDeleteConfirm() {
        const folders = this.folders.filter(f => this._selectedFolderIdsSet.has(Number(f.id)));
        if (!folders.length) return;
        this.bulkDeleteHasNested = folders.some(f => this._hasChildrenSet.has(Number(f.id)));
        this.deletingFoldersBulk = folders;
    },

    hideBulkDeleteConfirm() {
        this.deletingFoldersBulk = null;
        this.bulkDeleteHasNested = false;
    },

    async confirmDeleteSelectedFolders(onChildren = DEFAULT_ON_CHILDREN) {
        const ids = (this.deletingFoldersBulk || []).map(f => Number(f.id));
        const allDeleting = [...(this.deletingFoldersBulk || [])];
        const deletedCount = ids.length;
        const deletedSet = new Set(ids);
        const trashFolderId = Number(getRuntime().trashFolderId || 0);

        const pred = isInDeletedSubtree(this.folders, deletedSet);
        const shouldLeaveCurrentView = pred(Number(this.openId) || 0);
        this.deletingFoldersBulk = null;
        this.bulkDeleteHasNested = false;
        this.folderSelectMode = false;
        this.selectedFolderIds = [];
        this._selectedFolderIdsSet = new Set();

        // Optimistic local removal so the tree clears instantly.
        this.folders = this.folders.filter((f) => !pred(Number(f.id)));

        await this.withLoading(async () => {


            const results = await Promise.allSettled(ids.map(id => Api.deleteFolder(id, onChildren)));
            const failedIds = ids.filter((_, i) => results[i].status === 'rejected');


            if (failedIds.length > 0) {
                const failedSet = new Set(failedIds);
                const rolledBack = allDeleting.filter(f => failedSet.has(Number(f.id)));
                this.folders = [...this.folders, ...rolledBack];
            }




            try {
                await this.refreshFolders({ silent: true });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.error = error.message;
                }
            }


            memClear();





            window.dispatchEvent(new CustomEvent(Events.FOLDER_DELETED));
            if (shouldLeaveCurrentView && failedIds.length < deletedCount) {
                const uncategorized = findReattachTarget(this.folders, trashFolderId);
                if (uncategorized) { this.openFolder(Number(uncategorized.id)); }
            }
            const successCount = deletedCount - failedIds.length;
            if (successCount > 0) {
                this.notify('success', successCount + ' ' + (successCount === 1
                    ? t('folder_deleted_notif', 'folder moved to Trash')
                    : t('folders_deleted_notif', 'folders moved to Trash')));
            }
            if (failedIds.length > 0) {
                this.notify('error', failedIds.length + ' ' + (failedIds.length === 1
                    ? t('folder_delete_failed_notif', 'folder could not be deleted')
                    : t('folders_delete_failed_notif', 'folders could not be deleted')));
            }
        });
    },
};
