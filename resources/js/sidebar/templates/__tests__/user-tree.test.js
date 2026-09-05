jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { userTreeTemplate } from '../user-tree.js';

describe('user-tree template', () => {
    it('renders the root folder list from folderTree getter so search uses filteredFolders', () => {
        const template = userTreeTemplate();

        expect(template).toContain('<template x-for="folder in folders.filter(f => !f.isProtected)" :key="folder.id">');
    });
});
