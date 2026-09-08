import { getMediaFrame } from '../runtime.js';



export const selectionModule = {
    

    getSelectedItemIds() {
        const ids = new Set();

        document.querySelectorAll('.attachment.selected[data-id]').forEach((node) => {
            const element = /** @type {HTMLElement} */ (node);
            const id = Number(element.dataset.id);
            if (id > 0) {
                ids.add(id);
            }
        });

        document.querySelectorAll('input[name="media[]"]:checked, input[name="post[]"]:checked').forEach((node) => {
            const input = /** @type {HTMLInputElement} */ (node);
            const id = Number(input.value);
            if (id > 0) {
                ids.add(id);
            }
        });

        /** @type {PlathixMediaSelection | null | undefined} */
        const wpSelection = /** @type {PlathixMediaSelection | null | undefined} */ (
            getMediaFrame()?.state?.()?.get?.('selection')
        );
        if (wpSelection?.models?.length) {
            wpSelection.models.forEach((model) => {
                const id = Number(model?.id);
                if (id > 0) {
                    ids.add(id);
                }
            });
        }

        return [...ids];
    },

    

    recountFromUi() {
        const fromDom = document.querySelectorAll('.attachment.selected[data-id]').length
            + document.querySelectorAll('input[name="media[]"]:checked, input[name="post[]"]:checked').length;
        const fromFrame = (() => {
            try {
                return /** @type {number} */ (getMediaFrame()?.state?.()?.get?.('selection')?.length ?? 0);
            } catch {
                return 0;
            }
        })();
        this.selectedMediaCount = Math.max(fromDom, fromFrame);
    },

    

    setFromMutationResult(failedCount) {
        this.selectedMediaCount = Number(failedCount) || 0;
    },

    

    clearSelectionDom({ removeIds = null } = {}) {
        /** @type {PlathixMediaSelection | null | undefined} */
        const wpSelection = /** @type {PlathixMediaSelection | null | undefined} */ (
            getMediaFrame()?.state?.()?.get?.('selection')
        );
        if (wpSelection?.reset) {
            wpSelection.reset();
        }

        if (Array.isArray(removeIds) && removeIds.length) {
            removeIds.forEach((id) => {
                document.querySelector(`.attachment[data-id="${id}"]`)?.remove();
                document.querySelector(`tr#post-${id}`)?.remove();
            });
        }

        document.querySelectorAll('.attachment.selected[data-id]').forEach((node) => {
            node.classList.remove('selected');
        });

        document.querySelectorAll('input[name="media[]"]:checked, input[name="post[]"]:checked').forEach((node) => {
            /** @type {HTMLInputElement} */ (node).checked = false;
        });

        document.querySelectorAll('#cb-select-all-1, #cb-select-all-2').forEach((node) => {
            /** @type {HTMLInputElement} */ (node).checked = false;
        });
    },
};
