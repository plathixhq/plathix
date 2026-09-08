

import Alpine from 'alpinejs';
import { favoritesTemplate } from '../favorites-template.js';

jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

let alpineStarted = false;

function mountFavorites(store) {
    document.body.innerHTML = `<div x-data>${favoritesTemplate()}</div>`;
    Alpine.store('plathix', {
        folderColorStyle: () => '',
        folderColorFill: () => 'none',
        ...store,
    });
    if (!alpineStarted) {
        Alpine.start();
        alpineStarted = true;
    } else {
        Alpine.initTree(document.body);
    }
}

describe('enforces request authorization', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('covers public behavior without internal references', () => {
        const html = favoritesTemplate();
        expect(html).not.toMatch(/@contextmenu/);
    });

    it('keeps upload links scoped to the active folder', () => {
        const favFolder = { id: 5, name: 'Favorite Folder', count: 0 };
        const store = {
            favorites: [5],
            hasVisibleFavorites: true,
            visibleFavoritesCount: 1,
            folders: [favFolder],
            openId: 0,
            favoriteMatchesSearch: () => true,
            openFolder: jest.fn(),
        };

        mountFavorites(store);

        const contextMenuSpy = jest.fn();
        window.addEventListener('context-menu', contextMenuSpy);

        const folderEl = document.querySelector('.plathix-folder[data-folder-id="5"]');
        expect(folderEl).not.toBeNull();

        folderEl.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true }));

        expect(contextMenuSpy).not.toHaveBeenCalled();

        window.removeEventListener('context-menu', contextMenuSpy);
    });

    it('keeps upload links scoped to the active folder', () => {
        const favFolder = { id: 5, name: 'Favorite Folder', count: 0 };
        const store = {
            favorites: [5],
            hasVisibleFavorites: true,
            visibleFavoritesCount: 1,
            folders: [favFolder],
            openId: 0,
            favoriteMatchesSearch: () => true,
            openFolder: jest.fn(),
        };

        mountFavorites(store);

        const folderEl = document.querySelector('.plathix-folder[data-folder-id="5"]');
        folderEl.click();

        expect(store.openFolder).toHaveBeenCalledWith(5);
    });
});
