import { t } from '../i18n.js';

export const footerTemplate = () => `
    <div x-show="$store.plathix.isLoading" class="plathix-spinner"></div>
    <div x-show="$store.plathix.error" class="plathix-error-toast plathix-toast">
        <span x-text="$store.plathix.error" @click="$store.plathix.error = null"></span>
        <button type="button" class="button button-small" @click="$store.plathix.refreshFolders()">${t('retry_label', 'Refresh')}</button>
    </div>
    <div class="plathix-sidebar__footer">
        ${window.Plathix?.footerContent || ''}
    </div>
`;
