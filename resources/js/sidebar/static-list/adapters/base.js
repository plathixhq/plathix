import { extractZones, safeReplace, parseFragmentBySelector } from '../dom.js';
import { SEL_LIST, SEL_TABLENAV_TOP, SEL_TABLENAV_BOTTOM, SEL_VIEWS } from '../selectors.js';

export class BaseAdapter {
    canHandle(_url) {
        return false;
    }

    buildUrl(currentUrl, _folderId, { resetPage = false } = {}) {
        const url = new URL(currentUrl, window.location.origin);
        const folderId = Number(_folderId) || 0;
        const trashFolderId = Number(window.Plathix?.trashFolderId) || 0;
        const isTrash = folderId > 0 && trashFolderId > 0 && folderId === trashFolderId;

        url.searchParams.delete('plathix_folder');
        url.searchParams.delete('status');
        url.searchParams.delete('post_status');
        url.searchParams.delete('attachment-filter');
        if (resetPage) url.searchParams.delete('paged');

        if (isTrash) {
            // [internal]: attachment-filter=trash — единственный ключ, по которому
            // нативная загрузка upload.php включает post_status=trash. JS-детект корзины
            // (manager.js) читает attachment-filter первым ([internal]), status=trash не нужен.
            url.searchParams.set('attachment-filter', 'trash');
        } else if (folderId > 0) {
            // [internal]: без этого клиентский buildUrl() стирал plathix_folder, который
            // сервер (build_canonical_url) уже корректно вернул — F5/reload на прямом URL
            // с параметром всё равно терял папку сразу после загрузки JS.
            url.searchParams.set('plathix_folder', String(folderId));
        }

        return url.toString();
    }

    buildParams(_url) {
        throw new Error('buildParams() not implemented');
    }

    applyZones(incoming) {
        const zones = extractZones(incoming);
        let applied = false;

        const liveList   = document.querySelector(SEL_LIST);
        const liveTop    = document.querySelector(SEL_TABLENAV_TOP);
        const liveBottom = document.querySelector(SEL_TABLENAV_BOTTOM);
        const liveViews  = document.querySelector(SEL_VIEWS);

        if (zones.list && liveList)             { safeReplace(liveList, zones.list);             applied = true; }
        if (zones.tablenavTop && liveTop)       { safeReplace(liveTop, zones.tablenavTop);       applied = true; }
        if (zones.tablenavBottom && liveBottom) { safeReplace(liveBottom, zones.tablenavBottom); applied = true; }
        if (zones.views && liveViews)           { safeReplace(liveViews, zones.views);           applied = true; }

        return applied;
    }

    applyFragments(fragments) {
        let applied = false;

        const liveList   = document.querySelector(SEL_LIST);
        const liveTop    = document.querySelector(SEL_TABLENAV_TOP);
        const liveBottom = document.querySelector(SEL_TABLENAV_BOTTOM);
        const liveViews  = document.querySelector(SEL_VIEWS);

        if (fragments.list != null && liveList) {
            liveList.innerHTML = fragments.list;
            applied = true;
        }
        if (fragments.topNav && liveTop) {
            // Селектор, а не firstElementChild: WP выводит hidden _wpnonce перед .tablenav.top
            // ([internal]) — firstElementChild вернул бы input и уничтожил бы пагинацию.
            const el = parseFragmentBySelector(fragments.topNav, SEL_TABLENAV_TOP);
            if (el) { safeReplace(liveTop, el); applied = true; }
        }
        if (fragments.bottomNav && liveBottom) {
            const el = parseFragmentBySelector(fragments.bottomNav, SEL_TABLENAV_BOTTOM);
            if (el) { safeReplace(liveBottom, el); applied = true; }
        }
        if (fragments.views && liveViews) {
            const el = parseFragmentBySelector(fragments.views, SEL_VIEWS);
            if (el) { safeReplace(liveViews, el); applied = true; }
        }

        return applied;
    }
}
