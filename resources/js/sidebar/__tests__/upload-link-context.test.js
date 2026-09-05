jest.mock('alpinejs', () => ({
    store: jest.fn(),
    effect: jest.fn(),
}));

jest.mock('../state.js', () => ({
    hasStateFlag: jest.fn(() => false),
    setStateFlag: jest.fn(),
}));

import Alpine from 'alpinejs';
import { bindUploadLinkFolderContext } from '../upload-link-context.js';

function setLinkHtml(href) {
    document.body.innerHTML = `<a class="page-title-action aria-button-if-js" href="${href}">Add Media File</a>`;
}

describe('bindUploadLinkFolderContext ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('дописывает plathix_folder в href при открытой пользовательской папке', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 42 });

        bindUploadLinkFolderContext();

        const link = document.querySelector('a.page-title-action');
        expect(link.getAttribute('href')).toBe('media-new.php?plathix_folder=42');
    });

    it('не добавляет параметр, когда openId=0 (root/системная папка)', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 0 });

        bindUploadLinkFolderContext();

        const link = document.querySelector('a.page-title-action');
        expect(link.getAttribute('href')).toBe('media-new.php');
    });

    it('подписывается на Alpine.effect для реакции на смену openId', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 5 });

        bindUploadLinkFolderContext();

        expect(Alpine.effect).toHaveBeenCalledTimes(1);
    });

    it('no-op, если кнопка "Добавить медиафайл" отсутствует на странице', () => {
        document.body.innerHTML = '<div></div>';
        Alpine.store.mockReturnValue({ openId: 5 });

        expect(() => bindUploadLinkFolderContext()).not.toThrow();
    });

    describe('[internal]: блокировка при открытой «Корзине»', () => {
        afterEach(() => {
            delete window.Plathix;
        });

        it('убирает plathix_folder из href и ставит aria-disabled, когда openId===trashFolderId', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 655 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('href')).toBe('media-new.php');
            expect(link.getAttribute('aria-disabled')).toBe('true');
        });

        it('не блокирует кнопку для обычной папки, даже если trashFolderId задан', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 42 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('href')).toBe('media-new.php?plathix_folder=42');
            expect(link.getAttribute('aria-disabled')).toBe('false');
        });

        it('клик по задизейбленной кнопке не переходит по ссылке (preventDefault)', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 655 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            const prevented = !link.dispatchEvent(event);

            expect(prevented).toBe(true);
        });

        it('клик по активной (не-Корзина) кнопке НЕ блокируется', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 42 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            const prevented = !link.dispatchEvent(event);

            expect(prevented).toBe(false);
        });

        it('не блокирует ничего, если trashFolderId недоступен (0/undefined)', () => {
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 0 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('aria-disabled')).toBe('false');
        });
    });
});
