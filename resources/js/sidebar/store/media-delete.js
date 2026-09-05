import { Api } from '../api.js';
import { t } from '../i18n.js';
import { getMediaFrame, getRuntime } from '../runtime.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';
import { memInvalidateFolder } from '../media-grid-cache.js';

let _mediaRestoreSeq = 0;
let _mediaTrashSeq = 0;

export const mediaDeleteModule = {
    mediaTrashConfirm: null,
    mediaTrashPending: false,
    mediaRestorePending: false,

    showMediaTrashConfirm() {
        const ids = this.getSelectedItemIds();
        if (!ids.length) {
            this.error = t('no_items_selected', 'No items selected.');
            return;
        }
        this.mediaTrashConfirm = ids;
    },

    hideMediaTrashConfirm() {
        this.mediaTrashConfirm = null;
    },

    async restoreMedia(targetFolderId = 0) {
        const ids = this.getSelectedItemIds();
        if (!ids.length) {
            this.error = t('no_items_selected', 'No items selected.');
            return;
        }

        const requestId = ++_mediaRestoreSeq;
        this.mediaRestorePending = true;

        try {
            const result = await Api.restoreMedia(ids, targetFolderId);
            if (requestId !== _mediaRestoreSeq) {
                return;
            }

            const restored = Array.isArray(result?.restored)
                ? result.restored.map((id) => Number(id)).filter((id) => id > 0)
                : [];
            const failed = Array.isArray(result?.failed)
                ? result.failed.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            // [internal]: сброс выбора через владельца (restore не удаляет узлы —
            // без removeIds: reset frame + снять .selected/чекбоксы).
            this.clearSelectionDom();
            this.selected = failed.length ? failed : [];
            // Счётчик — из результата мутации (пересчёт из DOM после reset дал бы 0).
            this.setFromMutationResult(failed.length);

            cacheInvalidateFolder(this.openId);
            memInvalidateFolder(this.openId);
            if (Number(targetFolderId) > 0 && Number(targetFolderId) !== Number(this.openId)) {
                cacheInvalidateFolder(targetFolderId);
                memInvalidateFolder(targetFolderId);
            }
            await this.refreshFolders({ silent: true });
            if (getMediaFrame()) {
                this.refreshMediaFrame?.();
            } else {
                this.applyFolderFilter(this.openId);
            }

            if (restored.length > 0) {
                const label = restored.length === 1
                    ? t('file_restored_notif', '1 file restored')
                    : restored.length + ' ' + t('files_restored_notif', 'files restored');
                this.notify('success', label);
            }

            if (failed.length > 0) {
                this.error = failed.length + ' ' + t('files_restore_failed_notif', 'files could not be restored');
            }
        } catch (error) {
            if (requestId === _mediaRestoreSeq) {
                // [internal]: сервер мог реально выполнить restore, но вернуть нечитаемый
                // ответ — silent refresh подтягивает настоящее состояние вместо потери
                // результата (тихий null иначе выглядел бы как "0 восстановлено").
                if (error?.code === 'rest_write_indeterminate') {
                    this.error = t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
                    this.refreshFolders({ silent: true }).catch(() => {});
                } else {
                    this.error = error?.name === 'AbortError' ? null : error.message;
                }
            }
        } finally {
            if (requestId === _mediaRestoreSeq) {
                this.mediaRestorePending = false;
            }
        }
    },

    async confirmMediaTrash() {
        if (!this.mediaTrashConfirm) return;
        const ids = this.mediaTrashConfirm;
        this.mediaTrashConfirm = null;
        const requestId = ++_mediaTrashSeq;
        this.mediaTrashPending = true;

        try {
            const result = await Api.trashMedia(ids);
            if (requestId !== _mediaTrashSeq) {
                return;
            }

            const trashed = Array.isArray(result?.trashed)
                ? result.trashed.map((id) => Number(id)).filter((id) => id > 0)
                : [];
            const failed = Array.isArray(result?.failed)
                ? result.failed.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            // [internal]: optimistic removal + сброс выбора через владельца
            // (clearSelectionDom удаляет узлы trashed + снимает .selected/чекбоксы + reset frame).
            this.clearSelectionDom({ removeIds: trashed });
            this.selected = failed.length ? failed : [];
            // Счётчик — из результата мутации (после removal пересчёт из DOM дал бы 0).
            this.setFromMutationResult(failed.length);

            cacheInvalidateFolder(this.openId);
            memInvalidateFolder(this.openId);
            const trashFolderId = Number(getRuntime().trashFolderId || 0);
            if (trashFolderId > 0 && trashFolderId !== Number(this.openId)) {
                cacheInvalidateFolder(trashFolderId);
                memInvalidateFolder(trashFolderId);
            }

            // Refresh folder counts, then reload the list content
            await this.refreshFolders({ silent: true });
            if (getMediaFrame()) {
                this.refreshMediaFrame?.();
            } else {
                this.applyFolderFilter(this.openId);
            }

            if (trashed.length > 0) {
                const label = trashed.length === 1
                    ? t('file_trashed_notif', '1 file moved to trash')
                    : trashed.length + ' ' + t('files_trashed_notif', 'files moved to trash');
                this.notify('success', label);
            }

            if (failed.length > 0) {
                this.error = failed.length + ' ' + t('files_trash_failed_notif', 'files could not be moved to trash');
            }
        } catch (error) {
            if (requestId === _mediaTrashSeq) {
                // [internal]: та же логика, что restoreMedia выше — сервер мог реально
                // выполнить trash, но вернуть нечитаемый ответ.
                if (error?.code === 'rest_write_indeterminate') {
                    this.error = t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
                    this.refreshFolders({ silent: true }).catch(() => {});
                } else {
                    this.error = error?.name === 'AbortError' ? null : error.message;
                }
            }
        } finally {
            if (requestId === _mediaTrashSeq) {
                this.mediaTrashPending = false;
            }
        }
    },
};
