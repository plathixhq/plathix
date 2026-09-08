

jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

import { trashActionsHTML, ACTION_MARKER } from '../trash-toolbar-actions.js';

describe('trash-toolbar-actions.js — trashActionsHTML()', () => {
    let html;

    beforeEach(() => {
        html = trashActionsHTML();
    });

    it('covers public behavior without internal references', () => {
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

    it('covers public behavior without internal references', () => {
        expect(html).toContain('x-cloak');
        expect(html).toContain('$store.plathix.canAssign');
        expect(html).toContain('$store.plathix.selectedMediaCount > 0');
    });
});
