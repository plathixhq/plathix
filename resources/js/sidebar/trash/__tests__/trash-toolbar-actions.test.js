

jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

import { trashActionsHTML, ACTION_MARKER } from '../trash-toolbar-actions.js';

describe('trash-toolbar-actions.js — trashActionsHTML()', () => {
    let html;

    beforeEach(() => {
        html = trashActionsHTML();
    });

    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
        expect(html).toContain(ACTION_MARKER);
    });

    it('handles trash workflow consistently', () => {
        expect(html).toContain('Move to Trash');
        expect(html).toContain('$store.plathix.showMediaTrashConfirm()');
        expect(html).toContain("Number($store.plathix.openId) !== Number(window.Plathix?.trashFolderId || 0) && !$store.plathix.isCurrentFolderTrashed()");
    });

    it('handles trash workflow consistently', () => {
        expect(html).toContain('Restore');
        expect(html).toContain('$store.plathix.restoreMedia()');
        expect(html).toContain("Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed()");
    });

    it('keeps store/selection state consistent across UI events', () => {
        expect(html).toContain('x-cloak');
        expect(html).toContain('$store.plathix.canAssign');
        expect(html).toContain('$store.plathix.selectedMediaCount > 0');
    });
});

describe('handles trash workflow consistently', () => {
    it('handles trash workflow consistently', () => {
        jest.resetModules();
        jest.doMock('../../i18n.js', () => ({
            t: (key) => (key === 'move_to_trash' ? '<img src=x onerror=alert(1)>' : key),
        }));
        // eslint-disable-next-line global-require
        const { trashActionsHTML: freshTrashActionsHTML } = require('../trash-toolbar-actions.js');

        const html = freshTrashActionsHTML();

        expect(html).not.toContain('<img src=x onerror=alert(1)>');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');

        jest.dontMock('../../i18n.js');
    });
});
