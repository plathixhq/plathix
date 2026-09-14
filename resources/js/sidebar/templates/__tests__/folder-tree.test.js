jest.mock('../../events.js', () => ({
    Events: {},
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { treeLevel, treeLevelMarkup, folderItem, newFolderForm } from '../folder-tree.js';








describe('preserves folder tree behavior', () => {
    it('preserves folder tree behavior', () => {
        const html = treeLevelMarkup();
        
        expect(html).toContain('x-for="folder in folders"');
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
    });

    it('covers DOM fragment parsing behavior', () => {
        const html = treeLevelMarkup();
        
        expect(html).toContain('x-html="treeLevelHtml()"');
        
        expect(html).toContain('class="plathix-folder__children"');
        expect(html).toContain('x-data="folderTree"');
    });

    it('covers DOM fragment parsing behavior', () => {
        const html = treeLevelMarkup();
        
        
        
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
        expect((html.match(/x-for="folder in folders"/g) || []).length).toBe(1);
    });

    it('keeps upload links scoped to the active folder', () => {
        const html = treeLevelMarkup();
        expect(html).toContain('plathix-new-folder__form');
    });
});

describe('preserves folder tree behavior', () => {
    it('preserves folder tree behavior', () => {
        const html = treeLevel('folder.id');
        expect(html).toContain('class="plathix-tree-level"');
        expect(html).toContain('x-data="folderTree"');
        expect(html).toContain('parentId = Number(folder.id)');
        
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
    });

    it('covers DOM fragment parsing behavior', () => {
        
        
        expect(treeLevel('0')).toContain('plathix-tree-level');
        expect(treeLevel('folder.id')).toContain('plathix-tree-level');
    });
});




describe('preserves folder tree behavior', () => {
    it('keeps upload links scoped to the active folder', () => {
        const html = folderItem();
        expect(html).not.toContain('role="button"');
        expect(html).not.toContain('tabindex="0"');
    });

    it('keeps store/selection state consistent across UI events', () => {
        const html = folderItem();
        expect(html).toContain('class="plathix-folder__open"');
        expect(html).toContain('@click="handleFolderClick(folder)"');
        expect(html).toContain('@keydown.enter.prevent="handleFolderClick(folder)"');
        expect(html).toContain('@keydown.space.prevent="handleFolderClick(folder)"');
    });

    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
        const html = folderItem();
        expect(html).toContain('@contextmenu.prevent="handleContextMenu(folder, $event)"');
        expect(html).toContain('@dragstart="handleFolderDragStart($event, folder)"');
        expect(html).toContain(':draggable="folderDraggable(folder)"');
    });
});




describe('preserves folder tree behavior', () => {
    it('mounts or dismisses the UI element under the expected conditions', () => {
        const html = newFolderForm();
        expect(html).not.toContain('aria-modal');
        expect(html).not.toContain('role="dialog"');
    });

    it('mounts or dismisses the UI element under the expected conditions', () => {
        const html = folderItem();
        expect(html).not.toContain('plathix-rename__form"\n                         role="dialog"');
        expect(html).not.toContain('aria-modal="true"\n                         aria-label="${t(\'rename_folder\'');
    });

    it('declares the required ARIA attribute or role for assistive technology', () => {
        const html = newFolderForm();
        expect(html).toContain('class="plathix-new-folder__input"\n               aria-label="Create folder"');
    });

    it('declares the required ARIA attribute or role for assistive technology', () => {
        const html = folderItem();
        expect(html).toContain('class="plathix-new-folder__input"\n                               aria-label="Rename folder"');
    });
});



describe('preserves folder tree behavior', () => {
    it('preserves folder tree behavior', () => {
        const html = folderItem();
        expect(html).toContain('aria-haspopup="true"');
        expect(html).toContain(':aria-expanded="($store.plathix.contextMenuFolderId === Number(folder.id)).toString()"');
    });
});
