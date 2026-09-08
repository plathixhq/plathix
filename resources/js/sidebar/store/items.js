import { Api } from '../api.js';
import { t } from '../i18n.js';
import { getMediaFrame, getScreenKind, shouldUseStaticListFiltering } from '../runtime.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';
import { memInvalidateFolder } from '../media-grid-cache.js';

let _moveItemsSeq = 0;

export const itemsModule = {



    async moveItemsBulk(itemIds, folderId) {
        const requestId = ++_moveItemsSeq;
        const targetFolderId = Number(folderId) || 0;
        if (targetFolderId <= 0) {
            if (requestId === _moveItemsSeq) {
                this.error = t('invalid_move_target', 'Open a destination folder first.');
            }
            return;
        }

        const targetFolderName = this.folders.find((f) => Number(f.id) === targetFolderId)?.name || '';
        const snapshot = [...this.selected];
        this.selected = [];

        const targetIdx = this.folders.findIndex((f) => Number(f.id) === targetFolderId);
        const prevCount = targetIdx !== -1 ? (Number(this.folders[targetIdx].count) || 0) : 0;
        if (targetIdx !== -1) {
            this.patchFolder(targetFolderId, { count: prevCount + itemIds.length });
        }

        try {
            const result = await Api.moveItemsBulk(itemIds, targetFolderId);
            if (requestId !== _moveItemsSeq) {
                return;
            }
            const failedIds = Array.isArray(result?.failed)
                ? result.failed.map((id) => Number(id)).filter((id) => id > 0)
                : [];
            const moved = Number(result?.moved || result?.assigned || 0);



            const restoredCount = Array.isArray(result?.restored) ? result.restored.length : 0;

            if (targetIdx !== -1) {
                const diff = moved - itemIds.length;
                if (diff !== 0) {
                    const current = this.folders.find((f) => Number(f.id) === targetFolderId);
                    this.patchFolder(targetFolderId, {
                        count: Math.max(0, (Number(current?.count) || 0) + diff),
                    });
                }
            }

            const currentOpenId = Number(this.openId);





            //




            const affectedFolderIds = Array.isArray(result?.counts_recomputed)
                ? result.counts_recomputed.map((id) => Number(id)).filter((id) => id >= 0)
                : [];
            const foldersToInvalidate = affectedFolderIds.length
                ? [...new Set(affectedFolderIds)]
                : [...new Set([targetFolderId, currentOpenId])];



            foldersToInvalidate.forEach((id) => {
                cacheInvalidateFolder(id);
                memInvalidateFolder(id);
            });
            const didDomRemove = currentOpenId > 0 && currentOpenId !== targetFolderId;
            const successIds = failedIds.length
                ? itemIds.filter((id) => !failedIds.includes(id))
                : itemIds;






            if (didDomRemove) {
                successIds.forEach((id) => {
                    document.querySelector(`.attachment[data-id="${id}"]`)?.remove();
                    document.querySelector(`tr#post-${id}`)?.remove();
                });

                // reset to page 1 so the user doesn't see a blank list table.
                if (
                    shouldUseStaticListFiltering() &&
                    document.querySelectorAll('#the-list tr[id^="post-"]').length === 0
                ) {
                    this.applyFolderFilter(this.openId, { resetPage: true });
                }
            }

            /** @type {PlathixMediaSelection | null | undefined} */
            const wpSelection = /** @type {PlathixMediaSelection | null | undefined} */ (
                getMediaFrame()?.state?.()?.get?.('selection')
            );
            if (wpSelection?.reset) {
                wpSelection.reset();
            }













            const frame = getMediaFrame();
            if (frame && getScreenKind() !== 'modal' && !failedIds.length) {
                frame.trigger('selection:action:done');
            }

            document.querySelectorAll('#cb-select-all-1, #cb-select-all-2').forEach((cb) => {
                /** @type {HTMLInputElement} */ (cb).checked = false;
            });
            if (!didDomRemove) {
                successIds.forEach((id) => {
                    document.querySelector(`.attachment[data-id="${id}"]`)?.classList.remove('selected');
                    const checkbox = document.querySelector(
                        `input[name="media[]"][value="${id}"], input[name="post[]"][value="${id}"]`
                    );
                    if (checkbox) /** @type {HTMLInputElement} */ (checkbox).checked = false;
                });
            }


            this.setFromMutationResult(failedIds.length);






            //




            const countsMap = result?.counts && typeof result.counts === 'object' ? result.counts : {};
            const countsEntries = Object.entries(countsMap);
            if (countsEntries.length) {
                countsEntries.forEach(([id, count]) => {
                    this.patchFolder(id, { count: Number(count) || 0 });
                });
            } else {
                this.refreshFolders({ silent: true }).catch(() => {});
            }

            if (!didDomRemove) {
                this.applyFolderFilter(this.openId);
            }

            if (failedIds.length) {
                this.selected = failedIds;
            }

            if (moved > 0) {
                const suffix = targetFolderName ? ' \u2192 ' + targetFolderName : '';

                // \u0432\u043e\u0441\u0441\u0442\u0430\u043d\u043e\u0432\u043b\u0435\u043d\u044b \u0438\u0437 \u043a\u043e\u0440\u0437\u0438\u043d\u044b \u2014 \u0447\u0435\u0441\u0442\u043d\u044b\u0439 \u0442\u0435\u043a\u0441\u0442, \u043d\u0435 \u043e\u0431\u0449\u0438\u0439 "\u043f\u0435\u0440\u0435\u043c\u0435\u0449\u0435\u043d\u043e".
                const msg = restoredCount > 0
                    ? (restoredCount === 1
                        ? t('file_restored_moved_notif', '1 file restored and moved') + suffix
                        : restoredCount + ' ' + t('files_restored_moved_notif', 'files restored and moved') + suffix)
                    : (moved === 1
                        ? t('file_moved_notif', '1 file moved') + suffix
                        : moved + ' ' + t('files_moved_notif', 'files moved') + suffix);
                this.notify('success', msg);
            }

            if (failedIds.length || Number(result?.skipped || 0) > 0) {
                const parts = [];
                if (Number(result?.skipped || 0) > 0) {
                    parts.push(`${Number(result.skipped)} unchanged`);
                }
                if (failedIds.length) {
                    parts.push(`${failedIds.length} failed`);
                }
                if (parts.length) this.error = parts.join(', ');
            }
        } catch (error) {
            if (requestId !== _moveItemsSeq) {
                return;
            }
            if (targetIdx !== -1) {
                this.patchFolder(targetFolderId, { count: prevCount });
            }
            this.selected = snapshot;




            if (error?.code === 'rest_write_indeterminate') {
                this.error = t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
                this.refreshFolders({ silent: true }).catch(() => {});
                return;
            }
            this.error = error?.name === 'AbortError' ? null : error.message;
        }
    },

};
