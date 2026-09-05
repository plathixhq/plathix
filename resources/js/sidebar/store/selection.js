import { getMediaFrame } from '../runtime.js';

/**
 * [internal]: единственный владелец состояния выбора медиа.
 *
 * До этого модуля «что выбрано» было размазано по 4 файлам (items/attachment-events/
 * navigation/media-delete), каждый читал DOM своими селекторами, а `selectedMediaCount`
 * не имел владельца обновления (писался и событийно, и вручную-костылём). Этот модуль
 * централизует три роли: чтение выбора, обновление счётчика (два раздельных входа) и
 * DOM-мутации выбора store-слоя.
 *
 * `selectedMediaCount` объявлено в ui-state.js (не дублируем) — здесь только владелец
 * его ОБНОВЛЕНИЯ. mergeStore кладёт всё в один Alpine-объект, поэтому `this.*` доступен.
 */
export const selectionModule = {
    /**
     * Набор id выбранных медиа-элементов из ТРЁХ источников (DOM .selected + чекбоксы
     * списка + wp.media frame selection). Set схлопывает дубли по id. Единственная копия
     * этой логики (была продублирована в items.js).
     * @returns {number[]}
     */
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

    /**
     * ВХОД 1 — пересчёт счётчика из UI для ИНТЕРАКТИВНЫХ событий выбора (клик/change/keyup/
     * frame add-remove-reset, вешает attachment-events.js). DOM и media-frame — два ЗЕРКАЛА
     * одного выбора; Math.max берёт большее (сумма удвоила бы, объединение по id недоступно —
     * есть только длины). Нельзя использовать для мутаций: после optimistic-removal оба
     * зеркала обнулены → дал бы 0 вместо реального failed.length.
     */
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

    /**
     * ВХОД 2 — установка счётчика из РЕЗУЛЬТАТА мутации (trash/move/restore) ПОСЛЕ
     * optimistic-removal/reset. Источник истины — `failed.length` результата операции, НЕ
     * пересчёт из DOM (к этому моменту выбранные узлы уже удалены/сброшены, пересчёт дал бы 0,
     * а при частичном провале нужно оставить счётчик = число не-обработанных).
     * @param {number} failedCount
     */
    setFromMutationResult(failedCount) {
        this.selectedMediaCount = Number(failedCount) || 0;
    },

    /**
     * DOM-мутации выбора store-слоя в одном месте: сбросить wp.media selection, снять классы
     * `.selected`, снять чекбоксы и «выбрать все». Если передан `removeIds` — физически удалить
     * эти узлы из DOM (optimistic removal при trash/move в другую папку).
     * НЕ трогает static-list `#reinitTableControls` (manager.js — другой слой, non-goal).
     * @param {{ removeIds?: number[] | null }} [opts]
     */
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
