/**
 * [internal] (продуктовое решение): Избранное — список-адресатор (клик открывает папку
 * в основном дереве), контекстное меню там не нужно и не поддерживается. Правый клик по
 * избранной папке не должен диспатчить Events.CONTEXT_MENU — иначе воспроизводится баг
 * из #154 (создание подпапки из Избранного не раскрывает нужную ветку основного дерева).
 */
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

describe('favorites-template.js — контекстное меню недоступно из Избранного ([internal])', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('не содержит обработчик contextmenu в разметке', () => {
        const html = favoritesTemplate();
        expect(html).not.toMatch(/@contextmenu/);
    });

    it('правый клик по избранной папке НЕ диспатчит событие контекстного меню', () => {
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

    it('левый клик по избранной папке продолжает открывать папку (openFolder)', () => {
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
