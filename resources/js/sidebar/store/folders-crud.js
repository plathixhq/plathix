import { Api, DEFAULT_ON_CHILDREN } from '../api.js';
import { t } from '../i18n.js';
import { Events } from '../events.js';
import { getRuntime } from '../runtime.js';
import { isInDeletedSubtree, findReattachTarget } from './folder-tree-utils.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';
import { memClear } from '../media-grid-cache.js';

export const foldersCrudModule = {
    newFolderParentId: null,
    newFolderName: '',
    renamingFolderId: null,
    renamingFolderName: '',
    deletingFolder: null,





    _newFolderOutsideClickHandler: null,


    _renameOutsideClickHandler: null,

    async showNewFolderForm(parentId = 0) {
        this.newFolderParentId = Number(parentId);
        this.newFolderName = '';
        this.folderDragMode = false;
        if (parentId > 0) {



            await this.expandAncestors(Number(parentId));
            delete this.collapsedIds[parentId];
        }


        this._removeNewFolderOutsideClick();

        setTimeout(() => {

            if (this.newFolderParentId === null) return;
            const handler = (e) => {
                if (!e.target.closest('.plathix-new-folder__form:not(.plathix-rename__form)')) {
                    this.hideNewFolderForm();
                }
            };
            this._newFolderOutsideClickHandler = handler;
            document.addEventListener('click', handler, true);
        }, 0);
    },

    hideNewFolderForm() {
        this.newFolderParentId = null;
        this.newFolderName = '';
        this._removeNewFolderOutsideClick();
    },

    
    _removeNewFolderOutsideClick() {
        if (this._newFolderOutsideClickHandler) {
            document.removeEventListener('click', this._newFolderOutsideClickHandler, true);
            this._newFolderOutsideClickHandler = null;
        }
    },

    

    focusNewFolderInput() {
        if (typeof window === 'undefined' || typeof window.requestAnimationFrame !== 'function') {
            return;
        }
        const findInput = () => {
            const forms = document.querySelectorAll(
                '.plathix-new-folder__form:not(.plathix-rename__form)'
            );
            for (const form of forms) {


                if (form instanceof HTMLElement && form.offsetParent !== null) {
                    const input = form.querySelector('input');
                    return input instanceof HTMLElement ? input : null;
                }
            }
            return null;
        };
        window.requestAnimationFrame(() => {
            const input = findInput();
            if (!input) return;
            input.focus();
            if (document.activeElement === input) return;

            window.requestAnimationFrame(() => {
                const retryInput = findInput();
                retryInput?.focus();
            });
        });
    },

    

    focusRenameInput() {
        if (typeof window === 'undefined' || typeof window.requestAnimationFrame !== 'function') {
            return;
        }
        const findInput = () => {
            const forms = document.querySelectorAll('.plathix-rename__form');
            for (const form of forms) {
                if (form instanceof HTMLElement && form.offsetParent !== null) {
                    const input = form.querySelector('input');
                    return input instanceof HTMLElement ? input : null;
                }
            }
            return null;
        };
        window.requestAnimationFrame(() => {
            const input = findInput();
            if (!input) return;
            input.focus();
            input.select();
            if (document.activeElement === input) return;

            window.requestAnimationFrame(() => {
                const retryInput = findInput();
                retryInput?.focus();
                retryInput?.select();
            });
        });
    },

    async submitNewFolder() {
        const name = (this.newFolderName || '').trim();
        if (!name) return;
        const parentId = Number(this.newFolderParentId) || 0;
        this.hideNewFolderForm();
        await this.createFolder(name, parentId);
    },

    showRenameForm(folder) {
        this.renamingFolderId = Number(folder.id);
        this.renamingFolderName = folder.name || '';



        this._removeRenameOutsideClick();

        setTimeout(() => {

            if (this.renamingFolderId === null) return;
            const handler = (e) => {
                if (!e.target.closest('.plathix-rename__form')) {
                    this.hideRenameForm();
                }
            };
            this._renameOutsideClickHandler = handler;
            document.addEventListener('click', handler, true);
        }, 0);
    },

    hideRenameForm() {
        this.renamingFolderId = null;
        this.renamingFolderName = '';
        this._removeRenameOutsideClick();
    },

    
    _removeRenameOutsideClick() {
        if (this._renameOutsideClickHandler) {
            document.removeEventListener('click', this._renameOutsideClickHandler, true);
            this._renameOutsideClickHandler = null;
        }
    },

    async submitRename() {
        const id = Number(this.renamingFolderId);
        const name = (this.renamingFolderName || '').trim();
        if (!id || !name) {
            this.hideRenameForm();
            return;
        }
        this.hideRenameForm();
        await this.renameFolder(id, name);
    },

    showDeleteConfirm(folder) {
        this.deletingFolder = folder;
    },

    hideDeleteConfirm() {
        this.deletingFolder = null;
    },

    hasSiblingNamed(name, parentId, excludeId = null) {
        const norm = name.trim().toLowerCase();
        return this.folders.some((f) => {
            if (excludeId !== null && Number(f.id) === Number(excludeId)) return false;
            return Number(f.parentId || 0) === Number(parentId || 0) && f.name.trim().toLowerCase() === norm;
        });
    },

    async createFolder(name, parentId = 0) {
        if (this.hasSiblingNamed(name, parentId)) {
            this.alertMessage = t('folder_name_exists', 'A folder with this name already exists here.');
            return;
        }
        await this.withLoading(async () => {
            const res = await Api.createFolder(name, parentId);
            const newId = Number(res?.id || 0);
            if (newId > 0) {
                // Optimistic insert: \u043f\u0430\u043f\u043a\u0430 \u043f\u043e\u044f\u0432\u043b\u044f\u0435\u0442\u0441\u044f \u043c\u0433\u043d\u043e\u0432\u0435\u043d\u043d\u043e \u043f\u043e\u0441\u043b\u0435 \u043f\u0435\u0440\u0432\u043e\u0433\u043e \u043e\u0442\u0432\u0435\u0442\u0430,
                // \u0434\u043e \u0432\u0442\u043e\u0440\u043e\u0433\u043e fetch. Alpine \u0432\u0438\u0434\u0438\u0442 \u0442\u043e\u0442 \u0436\u0435 :key (newId) \u2192 \u043f\u0430\u0442\u0447\u0438\u0442 \u0430\u0442\u0440\u0438\u0431\u0443\u0442\u044b

                this.mergeFolders([{
                    id: newId,
                    name,
                    parentId,
                    count: 0,
                    color: '',
                    hasChildren: false,
                    isProtected: false,
                }]);
                try {
                    const resp = await Api.getFolder(newId);
                    if (resp?.folder?.id) {
                        this.mergeFolders([resp.folder]);
                    }
                } catch (e) {
                    // 404/\u0433\u043e\u043d\u043a\u0430 \u043a\u044d\u0448\u0430: \u043f\u0430\u043f\u043a\u0430 \u0443\u0436\u0435 \u0432 \u0434\u0435\u0440\u0435\u0432\u0435 (optimistic insert), \u0442\u0438\u0445\u0438\u0439 refresh.
                    cacheInvalidateFolder(newId);
                    cacheInvalidateFolder(parentId);

                    // try/catch \u043d\u0435 \u0434\u0430\u0451\u0442 \u0435\u0451 \u0441\u0431\u043e\u044e \u043e\u0442\u043c\u0435\u043d\u0438\u0442\u044c independent side-effects \u043d\u0438\u0436\u0435
                    // (dispatch/notify), \u043a\u043e\u0442\u043e\u0440\u044b\u0435 \u043e\u0442 \u043d\u0435\u0451 \u043d\u0435 \u0437\u0430\u0432\u0438\u0441\u044f\u0442.
                    try {
                        await this.refreshFolders({ silent: true, skipCacheClear: true });
                    } catch (error) {
                        if (error?.name !== 'AbortError') {
                            this.error = error.message;
                        }
                    }
                }
            }
            window.dispatchEvent(new CustomEvent(Events.FOLDER_CREATED));
            // \u041d\u0415 \u0437\u043e\u0432\u0451\u043c openFolder(newId): \u0430\u0432\u0442\u043e\u043f\u0435\u0440\u0435\u0445\u043e\u0434 = \u0437\u043b\u043e. openId \u043e\u0441\u0442\u0430\u0451\u0442\u0441\u044f \u043d\u0430 \u0442\u0435\u043a\u0443\u0449\u0435\u0439
            // \u043f\u0430\u043f\u043a\u0435 \u2192 \u043e\u043d\u0430 \u043e\u0441\u0442\u0430\u0451\u0442\u0441\u044f is-open (\u0432\u044b\u0434\u0435\u043b\u0435\u043d\u043d\u043e\u0439), \u043d\u043e\u0432\u0443\u044e \u043f\u0430\u043f\u043a\u0443 \u041d\u0415 \u043f\u043e\u0434\u0441\u0432\u0435\u0447\u0438\u0432\u0430\u0435\u043c.
            this.notify('success', t('folder_created_notif', 'Folder created') + ': \u00ab' + name + '\u00bb');
        });
    },

    async renameFolder(id, name) {
        const folder = this.folders.find((f) => Number(f.id) === Number(id));
        if (folder && this.hasSiblingNamed(name, folder.parentId, id)) {
            this.alertMessage = t('folder_name_exists', 'A folder with this name already exists here.');
            return;
        }
        await this.withLoading(async () => {
            await Api.renameFolder(id, name);
            cacheInvalidateFolder(id);

            // \u0434\u0430\u0451\u0442 \u0435\u0451 \u0441\u0431\u043e\u044e \u043e\u0442\u043c\u0435\u043d\u0438\u0442\u044c independent side-effects \u043d\u0438\u0436\u0435 (dispatch/notify).
            try {
                await this.refreshFolders({ silent: true, skipCacheClear: true });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.error = error.message;
                }
            }
            window.dispatchEvent(new CustomEvent(Events.FOLDER_MOVED));
            this.notify('success', t('folder_renamed_notif', 'Folder renamed') + ': \u00ab' + name + '\u00bb');
        });
    },

    async deleteFolder(id, onChildren = DEFAULT_ON_CHILDREN) {
        const deletedFolderId = Number(id);
        const deletedName = this.folders.find((f) => Number(f.id) === deletedFolderId)?.name || '';
        const trashFolderId = Number(getRuntime().trashFolderId || 0);


        const pred = isInDeletedSubtree(this.folders, new Set([deletedFolderId]));
        const shouldLeaveCurrentView = pred(Number(this.openId) || 0);

        // \u0421\u043d\u044f\u0442\u044b\u0435 \u0437\u0430\u043f\u0438\u0441\u0438 \u043d\u0443\u0436\u043d\u044b \u0434\u043b\u044f \u043e\u0442\u043a\u0430\u0442\u0430 \u043f\u0440\u0438 \u043e\u0448\u0438\u0431\u043a\u0435 API \u2014 \u0441\u043e\u0445\u0440\u0430\u043d\u044f\u0435\u043c \u0414\u041e \u043c\u0443\u0442\u0430\u0446\u0438\u0438 folders.
        const removed = this.folders.filter((f) => pred(Number(f.id)));

        // Optimistic local removal so the tree updates instantly, before API completes.
        this.folders = this.folders.filter((f) => !pred(Number(f.id)));

        this.deletingFolder = null;
        // withLoading(fn) \u043d\u0430 \u044d\u0442\u043e\u0439 \u0432\u0435\u0442\u043a\u0435 \u0433\u043b\u043e\u0442\u0430\u0435\u0442 \u043e\u0448\u0438\u0431\u043a\u0443 \u0431\u0435\u0437\u0443\u0441\u043b\u043e\u0432\u043d\u043e (\u0431\u0435\u0437 opt-in rethrow \u2014

        // withLoading, \u043a\u0430\u043a \u0443\u0436\u0435 \u0434\u0435\u043b\u0430\u0435\u0442 navigation.js:62,120 \u2014 \u044d\u0442\u043e \u043d\u0435 \u043d\u043e\u0432\u044b\u0439 \u043f\u0440\u0438\u043c\u0438\u0442\u0438\u0432, \u0430
        // \u0441\u0443\u0449\u0435\u0441\u0442\u0432\u0443\u044e\u0449\u0438\u0439 \u0432 store \u043f\u0430\u0442\u0442\u0435\u0440\u043d \u0440\u0443\u0447\u043d\u043e\u0433\u043e \u0443\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f isLoading.
        this.isLoading = true;
        try {
            await Api.deleteFolder(id, onChildren);
            cacheInvalidateFolder(deletedFolderId);
            if (trashFolderId > 0) {
                cacheInvalidateFolder(trashFolderId);
            }




            try {
                await this.refreshFolders({ silent: true, skipCacheClear: true });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.error = error.message;
                }
            }
            memClear();
            window.dispatchEvent(new CustomEvent(Events.FOLDER_DELETED));
            if (shouldLeaveCurrentView) {
                const uncategorized = findReattachTarget(this.folders, trashFolderId);
                if (uncategorized) {
                    await this.openFolder(Number(uncategorized.id));
                }
            }
            this.notify('success', t('folder_deleted_notif', 'Moved to Trash') + (deletedName ? ': \u00ab' + deletedName + '\u00bb' : ''));
        } catch (error) {
            // \u041e\u0442\u043a\u0430\u0442 optimistic removal: \u0441\u0435\u0440\u0432\u0435\u0440 \u043d\u0435 \u043f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u043b \u0443\u0434\u0430\u043b\u0435\u043d\u0438\u0435, \u0432\u0435\u0440\u043d\u0443\u0442\u044c \u043f\u0430\u043f\u043a\u0438 \u0432 \u0434\u0435\u0440\u0435\u0432\u043e.
            // \u041d\u0435 \u043f\u0440\u043e\u0431\u0440\u0430\u0441\u044b\u0432\u0430\u0435\u043c \u043e\u0448\u0438\u0431\u043a\u0443 \u0434\u0430\u043b\u044c\u0448\u0435 \u2014 \u0435\u0434\u0438\u043d\u0441\u0442\u0432\u0435\u043d\u043d\u044b\u0439 \u0432\u044b\u0437\u044b\u0432\u0430\u044e\u0449\u0438\u0439 (overlays.js @click) \u043d\u0435
            // \u0436\u0434\u0451\u0442 \u044d\u0442\u043e\u0442 \u043f\u0440\u043e\u043c\u0438\u0441 \u0438 \u043d\u0435 \u0438\u043c\u0435\u0435\u0442 .catch().
            this.error = error.message;
            this.folders = [...this.folders, ...removed];
            this.notify('error', t('folder_delete_failed_notif', 'folder could not be deleted'));
        } finally {
            this.isLoading = false;
        }
    },




};
