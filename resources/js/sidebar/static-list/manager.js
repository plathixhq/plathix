import Alpine from 'alpinejs';
import { doAction, applyFilters } from '../hooks.js';
import { Api } from '../api.js';
import { fetchListFragments } from './fragments-request.js';
import { pushUrl, replaceUrl, onPopState, removePopState } from './history.js';
import { UploadListAdapter } from './adapters/upload-list.js';
import { BaseAdapter } from './adapters/base.js';
import { cacheEpoch, cacheGet, cacheSet } from './cache.js';
import { isTrashViewActive } from '../runtime.js';

// CTAN-202 ([internal]): Free несёт только upload-адаптер; адаптеры других
// экранов приносит PRO своим бандлом через штатный wp.hooks-слот (симметрия PHP add_filter —
// слот принимает КОД извне, а не включает лежащий во Free код; Guideline 5).
//
// ASL-101 ([internal], [internal]): слот читается ЛЕНИВО — в момент
// использования, а не на module-eval. Иначе момент загрузки PRO-скрипта становится
// неконтролируемым инвариантом: WP вычисляет loading strategy по ЗАВИСИМЫМ
// (WP_Scripts::filter_eligible_strategies), поэтому `defer` у 'plathix-sidebar' Free не
// принадлежит — любой скрипт с deps ['plathix-sidebar'] без собственного 'defer' понижает
// его до blocking, бандл выполняется раньше PRO-адаптера, и слот молча отдаёт неполный
// список (навигация на списках записей отваливается без ошибок в консоли).
// Ленивое чтение убирает зависимость от порядка как класс: addFilter достаточно встать
// до первого действия пользователя.
function getAdapters() {
    const defaultAdapters = [new UploadListAdapter()];
    const filtered = applyFilters('plathix.staticList.adapters', defaultAdapters, { BaseAdapter });
    // [internal]: колбэк на этом фильтре — сторонний (PRO) код; если он забудет `return`
    // в цепочке, applyFilters() честно пробрасывает undefined дальше (hooks.js:7-15) —
    // без этой проверки следующий .find() падает TypeError'ом без деградации.
    return Array.isArray(filtered) ? filtered : defaultAdapters;
}

function resolveAdapter(url) {
    return getAdapters().find((a) => a.canHandle(url)) || null;
}

function folderIdFromUrl(url) {
    try {
        const u = new URL(url, window.location.origin);
        return Number(u.searchParams.get('plathix_folder')) || 0;
    } catch {
        return 0;
    }
}

function getStoreOpenId() {
    try {
        const storeOpenId = Number(Alpine.store('plathix')?.openId);
        if (Number.isFinite(storeOpenId) && storeOpenId > 0) {
            return storeOpenId;
        }
    } catch {
        // Ignore: Alpine store may not exist yet during early bootstrap.
    }

    return Number(window.Plathix?.openId || window.Plathix?.openFolderId) || 0;
}

function resolveNativeViewFolderId(link) {
    if (!link?.closest?.('.subsubsub')) {
        return null;
    }

    try {
        const url = new URL(link.href, window.location.origin);
        if (!resolveAdapter(url.toString())) {
            return null;
        }

        const urlFolderId = Number(url.searchParams.get('plathix_folder')) || 0;

        if (url.searchParams.get('attachment-filter') === 'trash') {
            return Number(window.Plathix?.trashFolderId) || 0;
        }
        const status = String(url.searchParams.get('status') || url.searchParams.get('post_status') || '');
        if (status === 'trash') {
            return Number(window.Plathix?.trashFolderId) || 0;
        }

        return urlFolderId;
    } catch {
        return 0;
    }
}

function resolveTableNavLink(link) {
    if (!link?.closest?.('.tablenav')) {
        return null;
    }

    try {
        const url = new URL(link.href, window.location.origin);
        if (!resolveAdapter(url.toString())) {
            return null;
        }

        const folderId = Number(url.searchParams.get('plathix_folder') || 0);
        return { folderId, url: link.href };
    } catch {
        return null;
    }
}

/**
 * [internal]: клик по нативному WP view-switch (переключатель Сетка/Список) не проходит
 * через resolveTableNavLink/resolveNativeViewFolderId — те завязаны на .tablenav/.subsubsub.
 * Факт со стенда: WP core теряет плагинные query-параметры при построении view-switch
 * href (напр. attachment-filter=trash), поэтому URL клика пуст от Plathix-состояния —
 * в отличие от .tablenav, где пагинация естественно сохраняет folder в URL.
 *
 * Источник folderId: если снапшот владельца (isTrashViewActive(), см. runtime.js)
 * утверждает, что мы сейчас в Корзине — trashFolderId, иначе (unchanged) —
 * getStoreOpenId(). store.openId НЕ годится как признак "мы в Корзине" сам по себе:
 * он не синхронизируется с trash-состоянием при прямом заходе на
 * upload.php?attachment-filter=trash.
 *
 * [internal] ([internal]): раньше здесь читался живой URL напрямую
 * (isTrashViewFromUrl(window.location.href)) — не покрывало сценарий "открыл Корзину
 * кликом по дереву папок в grid-режиме (mediaFrame-путь applyFolderFilter() правит
 * Backbone-коллекцию напрямую, не трогая window.location), затем переключился на
 * список": URL к этому моменту никогда не обновлялся, список получал обычный
 * folderId вместо trashFolderId (живой репорт 01.08.2026, [internal] comment).
 * isTrashViewActive() — снапшот, обновляемый через plathix.folderFilterApplied
 * (диспатчится из applyFolderFilter() ДО ветвления static-list/mediaFrame, см.
 * navigation.js) — актуален независимо от того, писал ли предыдущий клик в URL.
 *
 * [internal]: переход List→Grid НЕ должен перехватываться этой функцией вообще.
 * Живой факт со стенда: UploadListAdapter.canHandle() возвращает true для grid-ссылки
 * тоже, пока document.body ещё носит класс mode-list (клик происходит ДО перехода) —
 * из-за этого resolveAdapter() ложно считал grid-ссылку "обрабатываемой", клик уходил в
 * AJAX за LIST-фрагментами, а не в реальный переход на Backbone grid-режим (отдельная от
 * WP_List_Table система рендеринга, которую list-AJAX не может подменить). Grid-ссылка
 * WP core всегда несёт явный ?mode=grid (WP_List_Table::extra_tablenav) — проверяем это
 * ДО resolveAdapter() и отдаём такой клик нативной браузерной навигации без перехвата.
 */
function resolveViewSwitchLink(link) {
    if (!link?.closest?.('.view-switch')) {
        return null;
    }

    try {
        const url = new URL(link.href, window.location.origin);
        if (url.searchParams.get('mode') === 'grid') {
            return null;
        }
        if (!resolveAdapter(url.toString())) {
            return null;
        }

        const folderId = isTrashViewActive()
            ? (Number(window.Plathix?.trashFolderId) || 0)
            : getStoreOpenId();

        return { folderId, url: link.href };
    } catch {
        return null;
    }
}

export class StaticListNavigationManager {
    #abortController = null;
    #loading = false;
    #requestId = 0;
    #linkHandler = null;
    #loadingTimer = null;

    getAdapter() {
        return resolveAdapter(window.location.href);
    }

    buildUrl(folderId, opts = {}) {
        const adapter = this.getAdapter();
        if (!adapter) return null;
        return adapter.buildUrl(window.location.href, folderId, opts);
    }

    async navigate(url, { push = true, folderId = null, state = null } = {}) {
        if (this.#loading) {
            this.#abortController?.abort();
        }

        const adapter = resolveAdapter(url);
        if (!adapter) {
            console.error('[plathix] navigate: no adapter for url', url);
            window.location.assign(url);
            return;
        }

        this.#abortController = new AbortController();
        const requestId = ++this.#requestId;
        const activeFolderId = this.#resolveFolderId(folderId, url, state);
        this.#loading = true;
        this.#setLoading(true);

        try {
            const params = adapter.buildParams(url, activeFolderId);
            const data = await this.#loadFragments(params);
            if (requestId !== this.#requestId) {
                return;
            }

            if (!this.#applyNavigationResponse(adapter, data, url, requestId)) {
                console.error('[plathix] navigate: applyFragments returned false', data);
                return;
            }

            const canonicalUrl = data.url || url;
            this.#finalizeNavigation(canonicalUrl, activeFolderId, push);
        } catch (err) {
            if (err?.name === 'AbortError' || requestId !== this.#requestId) return;
            console.error('[plathix] navigate: fetch failed', err, url);
            window.location.assign(url);
        } finally {
            if (requestId === this.#requestId) {
                this.#loading = false;
                this.#setLoading(false);
            }
        }
    }

    async prefetch(url, { folderId = null } = {}) {
        const adapter = resolveAdapter(url);
        if (!adapter) return;
        const params = adapter.buildParams(url, this.#resolveFolderId(folderId, url));
        if (cacheGet(params)) return;
        const epoch = cacheEpoch();
        try {
            const data = await fetchListFragments(params, new AbortController().signal);
            cacheSet(params, data, epoch);
        } catch {}
    }

    init() {
        const initialFolderId = this.#resolveFolderId(null, window.location.href, window.history.state, { allowStoreFallback: true });
        const initialUrl = this.#resolveInitialUrl(initialFolderId);
        this.#replaceHistoryState(initialUrl, initialFolderId);
        this.#syncOpenId(initialFolderId);

        onPopState((url, e) => {
            const adapter = resolveAdapter(url);
            if (!adapter) return;
            const activeFolderId = this.#resolveFolderId(null, url, e?.state);
            const cleanUrl = this.buildUrl(activeFolderId) || url;
            return this.navigate(cleanUrl, { push: false, folderId: activeFolderId, state: e?.state });
        });

        this.#bindFolderLinks();
    }

    destroy() {
        this.#abortController?.abort();
        clearTimeout(this.#loadingTimer);
        this.#loadingTimer = null;
        document.body.classList.remove('plathix-list__loading');
        removePopState();
        this.#unbindFolderLinks();
    }

    #syncOpenId(folderId) {
        const store = Alpine.store('plathix');
        if (!store) return;
        if (store.openId !== folderId) {
            store.openId = folderId;
        }
    }

    #persistOpenId(folderId) {
        Api.savePreference('open_folder_id', Number(folderId) || 0).catch(() => {});
    }

    #buildHistoryState(folderId) {
        return { plathixFolderId: Number(folderId) || 0 };
    }

    #resolveFolderId(folderId, url, state = null, { allowStoreFallback = false } = {}) {
        if (folderId !== null && folderId !== undefined && folderId !== '') {
            const numericFolderId = Number(folderId);
            if (Number.isFinite(numericFolderId) && numericFolderId >= 0) {
                return numericFolderId;
            }
        }

        const stateFolderId = Number(state?.plathixFolderId);
        if (Number.isFinite(stateFolderId) && stateFolderId >= 0) {
            return stateFolderId;
        }

        try {
            const u = new URL(url, window.location.origin);
            if (u.searchParams.get('attachment-filter') === 'trash') {
                return Number(window.Plathix?.trashFolderId) || 0;
            }
            const status = u.searchParams.get('status') || u.searchParams.get('post_status') || '';
            if (status === 'trash') {
                return Number(window.Plathix?.trashFolderId) || 0;
            }
        } catch {}

        const legacyFolderId = folderIdFromUrl(url);
        if (legacyFolderId > 0) {
            return legacyFolderId;
        }

        return allowStoreFallback ? getStoreOpenId() : 0;
    }

    #setLoading(on) {
        if (on) {
            // Delay showing the loading state 80ms so rapid folder clicks
            // don't flash the dimmed-list look for aborted requests.
            if (!this.#loadingTimer) {
                this.#loadingTimer = setTimeout(() => {
                    this.#loadingTimer = null;
                    if (this.#loading) {
                        document.body.classList.add('plathix-list__loading');
                    }
                }, 80);
            }
        } else {
            clearTimeout(this.#loadingTimer);
            this.#loadingTimer = null;
            document.body.classList.remove('plathix-list__loading');
        }
    }

    async #loadFragments(params) {
        const cached = cacheGet(params);
        if (cached) {
            return cached.data;
        }

        const epoch = cacheEpoch();
        const data = await fetchListFragments(params, this.#abortController.signal);
        cacheSet(params, data, epoch);
        return data;
    }

    #applyNavigationResponse(adapter, data, fallbackUrl, requestId) {
        const applied = adapter.applyFragments(data.fragments);
        if (applied) {
            return true;
        }

        if (requestId === this.#requestId) {
            window.location.assign(fallbackUrl);
        }

        return false;
    }

    #finalizeNavigation(canonicalUrl, folderId, push) {
        if (push) {
            pushUrl(canonicalUrl, this.#buildHistoryState(folderId));
        } else {
            replaceUrl(canonicalUrl, this.#buildHistoryState(folderId));
        }

        this.#syncOpenId(folderId);
        this.#reinitTableControls();
        doAction('plathix.navigationComplete', { url: canonicalUrl, folderId });
    }

    #resolveInitialUrl(folderId) {
        return this.buildUrl(folderId) || window.location.href;
    }

    #replaceHistoryState(url, folderId) {
        replaceUrl(url, this.#buildHistoryState(folderId));
    }

    #reinitTableControls() {
        try {
            // Reset "check all" state before triggering change events.
            // If the checkbox were still checked from a previous operation it
            // would cause WP's list-table jQuery to re-select every freshly
            // loaded row, making it impossible to start with a clean selection.
            document.querySelectorAll('#cb-select-all-1, #cb-select-all-2').forEach((cb) => {
                /** @type {HTMLInputElement} */ (cb).checked = false;
            });
            // Explicitly uncheck row checkboxes — bfcache or browser form-state
            // restoration can leave them checked after fragment replacement.
            document.querySelectorAll(
                'input[name="media[]"]:checked, input[name="post[]"]:checked'
            ).forEach((cb) => { /** @type {HTMLInputElement} */ (cb).checked = false; });
            window.jQuery?.('.check-column input[type="checkbox"]').trigger('change');
        } catch {}
    }

    #bindFolderLinks() {
        if (this.#linkHandler) {
            return;
        }

        this.#linkHandler = (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
                return;
            }

            const link = event.target?.closest?.('a');
            if (!link) {
                return;
            }

            const folderLink = link.matches?.('a.plathix-folder-link[data-plathix-folder-id]');

            if (folderLink) {
                this.#handleFolderLinkClick(event, link);
                return;
            }

            const nativeViewFolderId = resolveNativeViewFolderId(link);
            if (nativeViewFolderId !== null) {
                this.#handleNativeViewClick(event, link, nativeViewFolderId);
                return;
            }

            const tableNavInfo = resolveTableNavLink(link);
            if (tableNavInfo !== null) {
                event.preventDefault();
                this.navigate(tableNavInfo.url, { folderId: tableNavInfo.folderId });
                return;
            }

            const viewSwitchInfo = resolveViewSwitchLink(link);
            if (viewSwitchInfo !== null) {
                event.preventDefault();
                this.navigate(viewSwitchInfo.url, { folderId: viewSwitchInfo.folderId });
            }
        };

        document.addEventListener('click', this.#linkHandler, true);
    }

    #handleFolderLinkClick(event, link) {
        const folderId = Number(link.dataset.plathixFolderId) || 0;
        const targetUrl = typeof link.href === 'string' && link.href !== ''
            ? link.href
            : this.buildUrl(folderId, { resetPage: true });
        if (!targetUrl) return;
        event.preventDefault();
        this.#syncOpenId(folderId);
        this.#persistOpenId(folderId);
        this.navigate(this.buildUrl(folderId, { resetPage: true }) || targetUrl, { folderId });
    }

    #handleNativeViewClick(event, link, nativeViewFolderId) {
        const folderId = Number(nativeViewFolderId) || 0;
        const targetUrl = typeof link.href === 'string' && link.href !== ''
            ? link.href
            : this.buildUrl(folderId, { resetPage: true });
        if (!targetUrl) return;
        event.preventDefault();
        this.#syncOpenId(folderId);
        this.#persistOpenId(folderId);
        this.navigate(targetUrl, { folderId });
    }

    #unbindFolderLinks() {
        if (!this.#linkHandler) {
            return;
        }

        document.removeEventListener('click', this.#linkHandler, true);
        this.#linkHandler = null;
    }
}
