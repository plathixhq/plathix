import Alpine from 'alpinejs';
import { t } from './i18n.js';
import { hasStateFlag, setStateFlag } from './state.js';

/**
 * [internal] ([internal]): кнопка «Добавить медиафайл» (`page-title-action`,
 * ведёт на media-new.php) — статическая ссылка, хардкоженная ядром WP без фильтра
 * (wp-admin/upload.php). На той странице нет sidebar/XHR-патча (upload-events.js),
 * поэтому контекст открытой папки передаётся через query-параметр `plathix_folder`,
 * который Upload::assign_folder_on_upload() уже умеет читать из $_REQUEST
 * ([internal]). Этот модуль держит href кнопки синхронным с store.openId.
 *
 * [internal]: при открытой служебной папке «Корзина» кнопка не должна вести на
 * загрузку вовсе — «Корзина» не валидный target (тот же инвариант, что #235
 * установил для move/drag-drop). Кнопка — Plathix-управляемый `<a>`-элемент (мы уже
 * переписываем её href здесь), не WP core без точки расширения — дизейблим её тем
 * же продуктовым паттерном, что уже применён к «+ Folder» в toolbar.js (:disabled по
 * контексту открытой папки), просто через vanilla JS click-guard вместо Alpine
 * :disabled — `<a>` не поддерживает HTML disabled атрибут.
 */
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
            // «Корзина» — не валидный upload-target: не переписываем href на неё вовсе,
            // оставляем ссылку как есть (без plathix_folder), чтобы клик (если guard
            // ниже почему-то не сработал) хотя бы не создавал новый вложенный факт
            // "загружено в Корзину" на уровне query-параметра.
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

        // href берём относительным (не url.pathname): base для относительного href в
        // реальном браузере — текущий URL страницы (.../wp-admin/upload.php), а не origin;
        // href остаётся тем же относительным путём "media-new.php", который отдал core.
        const originalPath = link.getAttribute('href').split('?')[0];
        link.setAttribute('href', url.search ? `${originalPath}${url.search}` : originalPath);
    };

    // Guard на click вешается ОДИН раз (делегирование через document, не привязка к
    // конкретному <a> — та же WP-разметка может быть заменена/перерендерена). Проверяет
    // ЖИВОЕ состояние класса в момент клика, не захваченное в замыкании значение.
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
