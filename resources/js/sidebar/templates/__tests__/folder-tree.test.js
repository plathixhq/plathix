jest.mock('../../events.js', () => ({
    Events: {},
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { treeLevel, treeLevelMarkup } from '../folder-tree.js';








describe('preserves folder tree behavior', () => {
    it('preserves folder tree behavior', () => {
        const html = treeLevelMarkup();

        expect(html).toContain('x-for="folder in folders"');
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
    });

    it('covers public behavior without internal references', () => {
        const html = treeLevelMarkup();

        expect(html).toContain('x-html="treeLevelHtml()"');

        expect(html).toContain('class="plathix-folder__children"');
        expect(html).toContain('x-data="folderTree"');
    });

    it('covers public behavior without internal references', () => {
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

    it('covers public behavior without internal references', () => {


        expect(treeLevel('0')).toContain('plathix-tree-level');
        expect(treeLevel('folder.id')).toContain('plathix-tree-level');
    });
});
