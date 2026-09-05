/**
 * @returns {PlathixRuntime}
 */
export function getRuntime() {
    return /** @type {PlathixRuntime} */ (window.Plathix || {});
}

export function getScreenKind() {
    return getRuntime().screenKind || 'static';
}

export function isModalScreen() {
    return getScreenKind() === 'modal';
}

export function isStaticScreen() {
    return getScreenKind() === 'static';
}

export function getScreenBase() {
    return getRuntime().screenBase || '';
}

export function getPostType() {
    return getRuntime().postType || 'attachment';
}

/**
 * [internal] (+ прецеденты #167, #181): `document.body.classList` — ненадёжный источник
 * состояния, сторонний код (WP core, page builders вроде Elementor) может менять его
 * post-load без Plathix re-render, из-за чего JS-пересчёт расходился с PHP-значением,
 * уже отданным при рендере (SidebarScreenResolver::get_filter_strategy()). Живым фактом
 * подтверждено: штатный WP view-switch — full page reload (не AJAX), DOM-класс в текущей
 * конфигурации даже не появляется — легитимного сценария для DOM-чтения нет. URL — тот же
 * приоритет, что уже использует PHP-сторона (get_media_library_mode()) и isTrashViewFromUrl()
 * ниже.
 */
export function getMediaMode() {
    const urlMode = new URL(window.location.href).searchParams.get('mode');
    if (urlMode === 'grid' || urlMode === 'list') {
        return urlMode;
    }

    return getRuntime().mediaMode || 'grid';
}

export function getFilterStrategy() {
    const runtime = getRuntime();
    if (runtime.screenKind === 'modal') {
        return 'media-frame';
    }

    if (runtime.screenBase === 'upload' && getMediaMode() === 'grid') {
        return 'media-frame';
    }

    // CEC-101: дубль снят — стратегию считает PHP-резолвер и кладёт в конфиг
    // (Free: SidebarScreenResolver::get_filter_strategy; PRO: свой ctx). Оставшийся
    // override выше (upload+grid) сохранён намеренно: JS пересчитывает mode по URL,
    // PHP-значение расходится при переключении вида ([internal]).
    return runtime.filterStrategy || 'url';
}

export function shouldUseMediaFrameFiltering() {
    return getFilterStrategy() === 'media-frame';
}

export function shouldUseUrlFiltering() {
    return getFilterStrategy() === 'url';
}

export function shouldUseStaticListFiltering() {
    return getFilterStrategy() === 'static-list';
}

/**
 * [internal] ([internal]): `wp.media.frame` — глобальный singleton, но не
 * единственный WP-нативный слот для активного media picker instance. Divi и Bricks
 * (подтверждено чтением их исходников — custom_uploader.js, custom-fonts.js) кладут свой
 * `wp.media()` инстанс в именованный `wp.media.frames.<key>`, не в `wp.media.frame` —
 * штатный WP core multi-instance registry, не их баг. Без этого fallback ни sidebar
 * (media-frame-watcher.js), ни getMediaFrame()-потребители (selection/delete/count
 * events) не находят активный frame в этих билдерах.
 *
 * @returns {object|undefined} Backbone frame instance (has .on/.off/.state), or undefined.
 */
export function resolveMediaFrame() {
    const frame = window.wp?.media?.frame;
    if (frame) {
        return frame;
    }

    const frames = window.wp?.media?.frames;
    if (frames && typeof frames === 'object') {
        for (const candidate of Object.values(frames)) {
            if (candidate && typeof candidate.on === 'function') {
                return candidate;
            }
        }
    }

    return undefined;
}

export function getMediaFrame() {
    if (!shouldUseMediaFrameFiltering()) {
        return null;
    }

    return resolveMediaFrame() || null;
}

export function isUploadScreen() {
    return getScreenBase() === 'upload';
}

export function isStaticLibraryScreen() {
    return !!getRuntime().isStaticLibraryScreen;
}

/**
 * [internal]: единственный надёжный источник "мы сейчас в Корзине" — текущий URL
 * страницы, не Alpine store/openId. store.openId не синхронизируется с trash-состоянием
 * при прямом заходе на upload.php?attachment-filter=trash (см. spec/_done/[internal],
 * [internal] пункт 4) — используется и list-, и grid-view-switch путём.
 *
 * @param {string} [url]
 * @returns {boolean}
 */
export function isTrashViewFromUrl(url = window.location.href) {
    try {
        return new URL(url, window.location.origin).searchParams.get('attachment-filter') === 'trash';
    } catch {
        return false;
    }
}

/**
 * [internal] ([internal], третий заход): владелец факта «текущий видимый grid-контекст —
 * Корзина», заменяет чтение live URL в момент клика для grid-view-switch пути.
 *
 * Почему не isTrashViewFromUrl() напрямую: WP core media-grid (Backbone Router,
 * media-grid.js `bindSearchHandler`) стирает `attachment-filter` из адресной строки
 * (`history.replaceState` на голый URL) через какое-то время ПОСЛЕ загрузки страницы —
 * недетерминированно, зависит от того, успела ли коллекция query-attachments ответить до
 * `Backbone.history.start()`. Live-подтверждено (scratchpad civm-vm-diag11/12/12b.mjs,
 * см. spec/[internal]). Снимок ниже берётся один раз при исполнении этого
 * модуля — гарантированно раньше стирания (стирание требует ответа сервера на AJAX,
 * async; модуль исполняется на загрузке страницы, до того как этот AJAX может завершиться).
 *
 * Почему не подписка на plathix.folderOpened (первая версия дизайна, отклонена
 * skeptic-проходом): folderOpened стреляет только из openFolder() — sibling sweep нашёл
 * обходной путь (upload-events.js: returnFolder после фонового аплоада меняет
 * store.openId и зовёт applyFolderFilter() напрямую, минуя openFolder()). Подписка идёт
 * на plathix.folderFilterApplied — событие, диспатчимое ИЗНУТРИ applyFolderFilter()
 * (store/navigation.js), единственной точки, куда сходятся все известные пути смены
 * видимого grid-фильтра.
 *
 * list-режим (static-list/manager.js) НЕ использует этот владелец: там Backbone media-grid
 * фрейм не создаётся вообще, WP core ничего не стирает, live URL остаётся достоверным
 * (live-подтверждено, diag12); manager.js сохраняет прямой isTrashViewFromUrl().
 */
let _trashViewActiveSnapshot = isTrashViewFromUrl();

window.wp?.hooks?.addAction?.('plathix.folderFilterApplied', 'plathix/trash-view-context-owner', (...args) => {
    const payload = /** @type {{ folderId?: number }|undefined} */ (args[0]);
    const trashId = Number(getRuntime().trashFolderId || 0);
    _trashViewActiveSnapshot = trashId > 0 && Number(payload?.folderId) === trashId;
});

export function isTrashViewActive() {
    return _trashViewActiveSnapshot;
}

/**
 * Returns the configured folder nesting depth limit.
 * 0 means unlimited — callers must treat 0 as "no restriction".
 */
export function getDepthLimit() {
    return Number(getRuntime().depthLimit ?? 0);
}

/**
 * Returns resolved feature flags. dnd/uploadSync default to true unless explicitly
 * set to false, so existing deployments are unaffected.
 *
 * @returns {{ dnd: boolean, uploadSync: boolean }}
 */
export function getFeatures() {
    const runtime = getRuntime();
    return {
        // [internal] ([internal]): top-level, тот же паттерн, что infiniteScroll — раньше
        // читались из несуществующего features[].dnd/.uploadSync (`undefined !== false` = всегда
        // true, PHP никогда не слал эти ключи). tree/replaceMedia убраны целиком: не имели
        // потребителя ни на одной стороне (dead code), не входящий сюда контракт.
        dnd:        runtime.dnd        !== false,
        uploadSync: runtime.uploadSync !== false,
        // infiniteScroll НЕ здесь: PHP кладёт его top-level (getRuntime().infiniteScroll),
        // а не в features[]. Раньше `f.infiniteScroll !== false` давал undefined!==false=true
        // → гейт всегда открыт, выключение опции игнорировалось. Источник флага — top-level.
    };
}
