import { t } from '../i18n.js';

// Домен сайта клиента в ссылку не подставляется ([internal]): это идентификатор
// установки, а Guideline 7 WP.org запрещает сбор данных о сайте без явного согласия.
// Метки статические — значения совпадают с PHP-контуром (ExternalLink::marketing),
// чтобы два контура не разъезжались.
const MARKETING_QUERY =
    'utm_source=plathix-plugin&utm_medium=plathix-admin&utm_campaign=plathix-plugin&utm_content=sidebar_footer';

const defaultFooter = () =>
    `Made with ❤︎ <a href="https://plathix.com/?${MARKETING_QUERY}" target="_blank" rel="noopener">Plathix.com</a>`;

export const footerTemplate = () => `
    <div x-show="$store.plathix.isLoading" class="plathix-spinner"></div>
    <div x-show="$store.plathix.error" class="plathix-error-toast plathix-toast">
        <span x-text="$store.plathix.error" @click="$store.plathix.error = null"></span>
        <button type="button" class="button button-small" @click="$store.plathix.refreshFolders()">${t('retry_label', 'Refresh')}</button>
    </div>
    <div class="plathix-sidebar__footer">
        ${window.Plathix?.footerContent || defaultFooter()}
    </div>
`;
