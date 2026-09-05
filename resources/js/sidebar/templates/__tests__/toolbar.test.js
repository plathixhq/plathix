jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../events.js', () => ({
    Events: {
        TOOLBAR_ACTION: 'toolbar-action',
    },
}));

import { toolbarTemplate } from '../toolbar.js';

describe('toolbar template', () => {
    beforeEach(() => {
        window.Plathix = {
            postTypeLabel: 'Media',
            postType: 'attachment',
            features: { gallery: true },
            toolbarExtra: [],
        };
    });

    it('renders system folders from the store getter', () => {
        const template = toolbarTemplate();

        expect(template).toContain('<template x-for="folder in $store.plathix.systemRootFolders" :key="folder.id">');
        expect(template).not.toContain('folders.filter(f => f.isProtected && Number(f.parentId || 0) === 0)');
    });

    it('renders the search slot for the search module to fill', () => {
        // [internal]: search-инпут вынесен в модуль search; toolbar
        // отдаёт только слот, в который search-entry.js вставляет реальный инпут.
        // debounce-контракт инпута проверяется в search-entry.test.js (переехал туда).
        const template = toolbarTemplate();

        expect(template).toContain('data-slot="plathix-search"');
    });

    describe('Trash files/folders counter with icons ([internal])', () => {
        it('renders a split files/folders counter on the Trash system node', () => {
            const template = toolbarTemplate();
            // Отдельный блок счётчиков Trash, показывается только когда foldersCount задан.
            expect(template).toContain('plathix-trash-counts');
            expect(template).toContain('folder.foldersCount !== null');
            // Числа через x-text (files=count, folders=foldersCount).
            expect(template).toContain('x-text="folder.count || 0"');
            expect(template).toContain('x-text="folder.foldersCount || 0"');
        });

        it('uses file/folder icons with aria-labels instead of letter labels', () => {
            const template = toolbarTemplate();
            // Две иконки с классом .plathix-trash-counts__icon.
            const icoCount = (template.match(/plathix-trash-counts__icon/g) || []).length;
            expect(icoCount).toBe(2);
            // aria-label подписи (t-мок отдаёт fallback Files/Folders) — доступность.
            expect(template).toContain('aria-label="Files"');
            expect(template).toContain('aria-label="Folders"');
            // Буквенные подписи Ф/П (fallback F/D рядом с числами) удалены.
            expect(template).not.toMatch(/x-text="folder\.count \|\| 0"><\/span>\s*F/);
            expect(template).not.toMatch(/x-text="folder\.foldersCount \|\| 0"><\/span>\s*D/);
        });

        it('renders counter icons smaller than the favorite star/shortcode (11px, [internal])', () => {
            const template = toolbarTemplate();
            // Иконки счётчика 11px — мельче звезды (12px) и шорткода (~13px), не перетягивают.
            expect(template).toContain('class="plathix-trash-counts__icon" width="11" height="11"');
            expect(template).not.toContain('class="plathix-trash-counts__icon" width="14"');
        });

        it('hides the ordinary .count on the Trash node (avoids double counter)', () => {
            const template = toolbarTemplate();
            expect(template).toContain('folder.foldersCount === null || folder.foldersCount === undefined');
        });
    });

    describe('native toggle buttons carry aria-pressed ([internal], [internal])', () => {
        it('renders aria-pressed bound to folderSelectMode on the Select folders button', () => {
            const template = toolbarTemplate();
            expect(template).toContain(':aria-pressed="String($store.plathix.folderSelectMode)"');
        });

        it('renders aria-pressed bound to folderDragMode on the Drag mode button', () => {
            const template = toolbarTemplate();
            expect(template).toContain(':aria-pressed="String($store.plathix.folderDragMode)"');
        });
    });

    describe('toolbarExtra active-state descriptor ([internal], [internal])', () => {
        it('binds :class and :aria-pressed when the descriptor carries an active field', () => {
            window.Plathix.toolbarExtra = [
                { id: 'folder-info', title: 'Folder info', icon: '<svg/>', active: 'showFolderInfo' },
            ];

            const template = toolbarTemplate();

            expect(template).toContain(":class=\"{ 'is-active': $store.plathix['showFolderInfo'] }\"");
            expect(template).toContain(':aria-pressed="String(!!$store.plathix[\'showFolderInfo\'])"');
        });

        it('does not render aria-pressed for descriptors without an active field (e.g. modal-opening buttons)', () => {
            window.Plathix.toolbarExtra = [
                { id: 'folder-upload', title: 'Upload folder', icon: '<svg/>' },
            ];

            const template = toolbarTemplate();
            const buttonMarkup = template.slice(template.indexOf("id: 'folder-upload'") - 200, template.indexOf("id: 'folder-upload'") + 50);

            expect(buttonMarkup).not.toContain('aria-pressed');
            expect(buttonMarkup).not.toContain("'is-active'");
        });
    });
});
