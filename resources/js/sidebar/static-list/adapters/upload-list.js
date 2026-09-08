import { BaseAdapter } from './base.js';

export class UploadListAdapter extends BaseAdapter {
    canHandle(url) {
        try {
            const u = new URL(url, window.location.origin);
            if (!u.pathname.includes('upload.php')) {
                return false;
            }
            return u.searchParams.get('mode') === 'list'
                || document.body.classList.contains('mode-list')
                || window.Plathix?.mediaMode === 'list';
        } catch {
            return false;
        }
    }

    buildParams(url, folderId = null) {
        const u = new URL(url, window.location.origin);
        const params = { screen_base: 'upload', post_type: 'attachment' };

        // Pass all URL params so plugin filters (WooCommerce, CPT tax, etc.) survive.
        const SKIP = new Set(['action', 'nonce', '_wpnonce', '_wp_http_referer']);
        for (const [key, value] of u.searchParams.entries()) {
            if (!SKIP.has(key)) params[key] = value;
        }

        // Explicit numeric forms for server-side logic.
        params.folder_id = Number(folderId ?? u.searchParams.get('plathix_folder')) || 0;
        params.paged     = Number(u.searchParams.get('paged')) || 1;
        return params;
    }
}
