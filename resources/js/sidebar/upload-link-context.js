import Alpine from 'alpinejs';
import { t } from './i18n.js';
import { hasStateFlag, setStateFlag } from './state.js';



export function bindUploadLinkFolderContext() {
    if (hasStateFlag('uploadLinkContextBound')) {
        return;
    }
    setStateFlag('uploadLinkContextBound');

    const applyFolderToLink = (openId) => {
        const link = document.querySelector('a.page-title-action[href*="media-new.php"]');
        if (!link) {
            return;
        }

        const trashFolderId = Number(window.Plathix?.trashFolderId || 0);
        const id = Number(openId) || 0;
        const isTrashTarget = trashFolderId > 0 && id === trashFolderId;

        link.setAttribute('aria-disabled', isTrashTarget ? 'true' : 'false');
        link.setAttribute(
            'title',
            isTrashTarget
                ? t('upload_blocked_in_trash', 'Go to your active media library to upload new files.')
                : ''
        );

        if (isTrashTarget) {




            const originalPath = link.getAttribute('href').split('?')[0];
            link.setAttribute('href', originalPath);
            return;
        }

        const url = new URL(link.getAttribute('href'), window.location.href);

        if (id > 0) {
            url.searchParams.set('plathix_folder', String(id));
        } else {
            url.searchParams.delete('plathix_folder');
        }




        const originalPath = link.getAttribute('href').split('?')[0];
        link.setAttribute('href', url.search ? `${originalPath}${url.search}` : originalPath);
    };




    document.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a.page-title-action[href*="media-new.php"]');
        if (link?.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    const store = Alpine.store('plathix');
    if (!store) {
        return;
    }

    applyFolderToLink(store.openId);
    Alpine.effect(() => {
        applyFolderToLink(Alpine.store('plathix')?.openId);
    });
}
