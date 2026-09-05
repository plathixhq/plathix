import { Api } from '../api.js';
import { t } from '../i18n.js';
import { Events } from '../events.js';
import { getDepthLimit } from '../runtime.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';

const LOCK_CODES = new Set(['structure_locked', 'reorder_locked', 'create_lock_failed']);

async function withLockRetry(fn, delay = 600) {
    try {
        return await fn();
    } catch (err) {
        if (LOCK_CODES.has(err?.code)) {
            await new Promise((r) => setTimeout(r, delay));
            return fn();
        }
        throw err;
    }
}

export const folderMoveModule = {
    canCreateChild(parentDepth) {
        const limit = getDepthLimit();
        return limit === 0 || Number(parentDepth) < limit;
    },

    getFolderDepth(folderId) {
        const byId = new Map(this.folders.map((f) => [Number(f.id), f]));
        let depth = 0;
        let current = Number(folderId);
        const visited = new Set();
        while (current > 0 && !visited.has(current)) {
            visited.add(current);
            const folder = byId.get(current);
            if (!folder) break;
            current = Number(folder.parentId || 0);
            depth++;
        }
        return depth;
    },

    async moveFolderToParent(id, parentId) {
        await this.withLoading(async () => {
            const oldParentId = Number(this.folders.find((f) => Number(f.id) === Number(id))?.parentId || 0);
            await withLockRetry(() => Api.moveFolderParent(id, parentId));
            cacheInvalidateFolder(id);
            cacheInvalidateFolder(parentId);
            if (oldParentId !== Number(parentId)) {
                cacheInvalidateFolder(oldParentId);
            }
            // [internal]: refreshFolders re-throw'ит по контракту — локальный try/catch не
            // даёт её сбою отменить independent side-effect ниже (dispatch).
            try {
                await this.refreshFolders({ silent: true, skipCacheClear: true });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.error = error.message;
                }
            }
            window.dispatchEvent(new CustomEvent(Events.FOLDER_MOVED));
        });
    },

    async moveFolderToSiblingOf(id, targetParentId, position) {
        await this.withLoading(async () => {
            const oldParentId = Number(this.folders.find((f) => Number(f.id) === Number(id))?.parentId || 0);
            await withLockRetry(() => Api.moveFolderToSiblingOf(id, targetParentId, position));
            cacheInvalidateFolder(id);
            cacheInvalidateFolder(targetParentId);
            if (oldParentId !== Number(targetParentId)) {
                cacheInvalidateFolder(oldParentId);
            }
            // [internal]: refreshFolders re-throw'ит по контракту — локальный try/catch не
            // даёт её сбою отменить independent side-effects ниже (dispatch/notify).
            try {
                await this.refreshFolders({ silent: true, skipCacheClear: true });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.error = error.message;
                }
            }
            window.dispatchEvent(new CustomEvent(Events.FOLDER_MOVED));
            if (this.sortBy && this.sortBy !== 'default') {
                this.notify(
                    'info',
                    t('drag_reorder_hidden_by_sort', 'Position saved. Switch sorting to "Default" to see it.'),
                    { key: 'drag-sort-hint' },
                );
            }
        });
    },
};
