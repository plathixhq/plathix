/**
 * [internal]: trashOverlaysHTML()/OVERLAY_MARKER вынесены из общего trash-модуля
 * ([internal]) без прямых тестов — только строковая форма шаблона,
 * без Alpine-runtime mount (чистая template-функция, без side-effects).
 */
let mockTranslateOverride = null;
jest.mock('../../i18n.js', () => ({
    t: (key, fallback) => (mockTranslateOverride ? mockTranslateOverride(key, fallback) : fallback),
}));

import { trashOverlaysHTML, OVERLAY_MARKER } from '../trash-overlays.js';

describe('trash-overlays.js — trashOverlaysHTML()', () => {
    let html;

    beforeEach(() => {
        html = trashOverlaysHTML();
    });

    it('корневой узел содержит OVERLAY_MARKER', () => {
        expect(html).toContain(OVERLAY_MARKER);
    });

    it('содержит confirm-overlay (mediaTrashConfirm) с move/cancel действиями', () => {
        expect(html).toContain('$store.plathix.mediaTrashConfirm');
        expect(html).toContain('$store.plathix.confirmMediaTrash()');
        expect(html).toContain('$store.plathix.hideMediaTrashConfirm()');
    });

    it('содержит progress-overlay (mediaRestorePending) на Trash-режим', () => {
        expect(html).toContain("Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) && $store.plathix.mediaRestorePending");
    });

    it('содержит мобильный дубль Restore-кнопки ([internal]) с корректным x-show-условием', () => {
        expect(html).toContain('plathix-mobile__bulk-restore');
        expect(html).toContain('$store.plathix.restoreMedia()');
        expect(html).toContain(
            "$store.plathix.selectedMediaCount > 0 && (Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed())"
        );
    });

    it('содержит ровно 3 x-teleport блока', () => {
        const matches = html.match(/x-teleport="body"/g) || [];
        expect(matches).toHaveLength(3);
    });
});

// [internal] ([internal]): перевод с апострофом внутри x-text не должен разрывать
// Alpine-выражение — JSON.stringify() вместо голой интерполяции в одинарных кавычках.
describe('trash-overlays.js — перевод с кавычкой не ломает x-text ([internal])', () => {
    afterEach(() => {
        mockTranslateOverride = null;
    });

    it('апостроф в переводе files_selected не обрывает атрибут', () => {
        mockTranslateOverride = (key) => (key === 'files_selected' ? "file's selected" : key);

        const container = document.createElement('div');
        container.innerHTML = trashOverlaysHTML();

        // Контент лежит внутри <template x-teleport>, которое innerHTML парсит в
        // .content (DocumentFragment), не в обычное дерево — искать там.
        const template = container.querySelector('template');
        const span = template.content.querySelector('.plathix-delete__title span');
        expect(span).not.toBeNull();

        // Полная проверка: атрибут не только присутствует, но и остаётся ВАЛИДНЫМ
        // JS-выражением после HTML-парсинга — эмулируем то, что делает Alpine.
        const attr = span.getAttribute('x-text');
        // eslint-disable-next-line no-new-func
        const evaluate = new Function('$store', 'return ' + attr);
        const result = evaluate({ plathix: { mediaTrashConfirm: [1, 2, 3] } });
        expect(result).toBe("3 file's selected");
    });
});
