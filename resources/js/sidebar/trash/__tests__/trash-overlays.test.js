

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

    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
        expect(html).toContain(OVERLAY_MARKER);
    });

    it('handles trash workflow consistently', () => {
        expect(html).toContain('$store.plathix.mediaTrashConfirm');
        expect(html).toContain('$store.plathix.confirmMediaTrash()');
        expect(html).toContain('$store.plathix.hideMediaTrashConfirm()');
    });

    it('handles trash workflow consistently', () => {
        expect(html).toContain("Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) && $store.plathix.mediaRestorePending");
    });

    it('handles trash workflow consistently', () => {
        expect(html).toContain('plathix-mobile__bulk-restore');
        expect(html).toContain('$store.plathix.restoreMedia()');
        expect(html).toContain(
            "$store.plathix.selectedMediaCount > 0 && (Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed())"
        );
    });

    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
        const matches = html.match(/x-teleport="body"/g) || [];
        expect(matches).toHaveLength(3);
    });
});



describe('handles trash workflow consistently', () => {
    afterEach(() => {
        mockTranslateOverride = null;
    });

    it('escapes untrusted output for the destination context', () => {
        mockTranslateOverride = (key) => (key === 'files_selected' ? "file's selected" : key);

        const container = document.createElement('div');
        container.innerHTML = trashOverlaysHTML();



        const template = container.querySelector('template');
        const span = template.content.querySelector('.plathix-delete__title span');
        expect(span).not.toBeNull();



        const attr = span.getAttribute('x-text');
        // eslint-disable-next-line no-new-func
        const evaluate = new Function('$store', 'return ' + attr);
        const result = evaluate({ plathix: { mediaTrashConfirm: [1, 2, 3] } });
        expect(result).toBe("3 file's selected");
    });
});
