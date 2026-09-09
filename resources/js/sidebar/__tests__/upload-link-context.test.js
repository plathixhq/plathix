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

describe('keeps upload links scoped to the active folder', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('keeps upload links scoped to the active folder', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 42 });

        bindUploadLinkFolderContext();

        const link = document.querySelector('a.page-title-action');
        expect(link.getAttribute('href')).toBe('media-new.php?plathix_folder=42');
    });

    it('keeps upload links scoped to the active folder', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 0 });

        bindUploadLinkFolderContext();

        const link = document.querySelector('a.page-title-action');
        expect(link.getAttribute('href')).toBe('media-new.php');
    });

    it('keeps upload links scoped to the active folder', () => {
        setLinkHtml('media-new.php');
        Alpine.store.mockReturnValue({ openId: 5 });

        bindUploadLinkFolderContext();

        expect(Alpine.effect).toHaveBeenCalledTimes(1);
    });

    it('keeps upload links scoped to the active folder', () => {
        document.body.innerHTML = '<div></div>';
        Alpine.store.mockReturnValue({ openId: 5 });

        expect(() => bindUploadLinkFolderContext()).not.toThrow();
    });

    describe('keeps upload links scoped to the active folder', () => {
        afterEach(() => {
            delete window.Plathix;
        });

        it('handles trash workflow consistently', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 655 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('href')).toBe('media-new.php');
            expect(link.getAttribute('aria-disabled')).toBe('true');
        });

        it('handles trash workflow consistently', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 42 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('href')).toBe('media-new.php?plathix_folder=42');
            expect(link.getAttribute('aria-disabled')).toBe('false');
        });

        it('coalesces repeated events into a single handled call', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 655 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            const prevented = !link.dispatchEvent(event);

            expect(prevented).toBe(true);
        });

        it('keeps upload links scoped to the active folder', () => {
            window.Plathix = { trashFolderId: 655 };
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 42 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            const prevented = !link.dispatchEvent(event);

            expect(prevented).toBe(false);
        });

        it('handles trash workflow consistently', () => {
            setLinkHtml('media-new.php');
            Alpine.store.mockReturnValue({ openId: 0 });

            bindUploadLinkFolderContext();

            const link = document.querySelector('a.page-title-action');
            expect(link.getAttribute('aria-disabled')).toBe('false');
        });
    });
});
