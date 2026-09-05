/**
 * [internal]: trashActionsHTML()/ACTION_MARKER вынесены из общего trash-модуля
 * ([internal]) без прямых тестов — только строковая форма шаблона,
 * без Alpine-runtime mount (чистая template-функция, без side-effects).
 */
jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

import { trashActionsHTML, ACTION_MARKER } from '../trash-toolbar-actions.js';

describe('trash-toolbar-actions.js — trashActionsHTML()', () => {
    let html;

    beforeEach(() => {
        html = trashActionsHTML();
    });

    it('корневой узел содержит ACTION_MARKER', () => {
        expect(html).toContain(ACTION_MARKER);
    });

    it('содержит кнопку "Move to Trash" с корректным x-show/@click', () => {
        expect(html).toContain('Move to Trash');
        expect(html).toContain('$store.plathix.showMediaTrashConfirm()');
        expect(html).toContain("Number($store.plathix.openId) !== Number(window.Plathix?.trashFolderId || 0) && !$store.plathix.isCurrentFolderTrashed()");
    });

    it('содержит кнопку "Restore" с корректным x-show/@click', () => {
        expect(html).toContain('Restore');
        expect(html).toContain('$store.plathix.restoreMedia()');
        expect(html).toContain("Number($store.plathix.openId) === Number(window.Plathix?.trashFolderId || 0) || $store.plathix.isCurrentFolderTrashed()");
    });

    it('корневой контейнер скрыт по умолчанию (x-cloak) и завязан на canAssign/selectedMediaCount', () => {
        expect(html).toContain('x-cloak');
        expect(html).toContain('$store.plathix.canAssign');
        expect(html).toContain('$store.plathix.selectedMediaCount > 0');
    });
});
