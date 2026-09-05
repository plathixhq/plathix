import { getRuntime } from './runtime.js';
import { onMediaFrameReady } from './media-frame-watcher.js';

// Единый триггер-контракт ([internal]): порог задаётся долей
// видимой области, а не абсолютным px — по аналогии с нативным WP core refreshThreshold
// (media-views.js, Attachments.scroll), который тоже считает «близко к низу» относительно
// clientHeight, а не фиксированным числом пикселей. Один и тот же множитель применяется
// симметрично к container-scroll (модалка) и window-scroll (upload.php grid) — единая
// формула вместо двух независимо откалиброванных чисел.
const NEAR_BOTTOM_VIEWPORT_MULTIPLIER = 2;

function isNearBottom(scrollPos, viewportSize, contentSize) {
    return scrollPos + viewportSize >= contentSize - viewportSize * NEAR_BOTTOM_VIEWPORT_MULTIPLIER;
}

function resolveLibrary() {
    const frame = window.wp?.media?.frame;
    if (!frame) return null;

    // Standard state library (Post / Select frame, modal context)
    const lib = frame.state?.()?.get?.('library');
    if (lib && typeof lib.hasMore === 'function') return lib;

    // Manage frame (upload.php grid): library lives on the content view's collection
    const col = frame.content?.get?.()?.collection;
    if (col && typeof col.hasMore === 'function') return col;

    return null;
}

// Единый индикатор процесса ([internal]): нативный
// wp.media.view.Spinner живёт на toolbar ДОЧЕРНЕГО content view (Attachments.Browser,
// media-views.js:4520-4540 createToolbar), НЕ на frame.toolbar — подтверждено фактом на
// живом стенде (frame.toolbar.get('spinner') === undefined, frame.content.get().toolbar
// .get('spinner') существует и в grid, и в модалке, оба используют один и тот же
// Attachments.Browser). Возвращает единый {show, hide} интерфейс независимо от источника —
// вызывающий код (#loadMore) не различает native vs fallback.
function getSpinnerHandle(frame, root) {
    const nativeSpinner = frame?.content?.get?.()?.toolbar?.get?.('spinner');
    if (nativeSpinner?.show && nativeSpinner?.hide) {
        return { show: () => nativeSpinner.show(), hide: () => nativeSpinner.hide() };
    }

    // Fallback: content view/toolbar недоступны в этот момент жизненного цикла — тот же
    // визуальный класс, что и нативный Spinner (span.spinner.is-active), не свой паттерн.
    let el = root.querySelector(':scope > .plathix-infinite-scroll-spinner');
    if (!el) {
        el = document.createElement('span');
        el.className = 'spinner plathix-infinite-scroll-spinner';
        root.appendChild(el);
    }
    return {
        show: () => el.classList.add('is-active'),
        hide: () => el.classList.remove('is-active'),
    };
}

export class InfiniteScrollManager {
    #initialized = false;
    #loading = false;
    #frameRef = null;
    #frameOff = [];  // cleanup fns for backbone frame listeners
    #scrollOff = []; // cleanup fns for DOM scroll listeners
    #rootEl = null;  // frame root, помечаем классом чтобы CSS спрятал нативную «Load more»
    #spinner = null; // {show, hide} handle — native toolbar spinner или fallback span

    init() {
        if (!getRuntime().infiniteScroll) return;
        if (this.#initialized) return;
        this.#initialized = true;

        // Modal context: listen for any wp.media frame opening.
        // [internal] ([internal]): wp.media.on/wp.media.events.on('open') не работают —
        // media-frame-watcher.js единственный владелец обнаружения frame через DOM.
        onMediaFrameReady((frame) => this.attachFrame(frame));
    }

    // Called externally for the static grid (upload.php) where
    // wp.media.frame exists before 'open' fires.
    attachFrame(frame) {
        if (!frame || frame === this.#frameRef) return;
        this.#detach();
        this.#frameRef = frame;
        this.#bindFrameEvents();
        this.#scheduleScrollBind();
    }

    #bindFrameEvents() {
        const frame = this.#frameRef;
        if (!frame) return;

        const onRender = () => this.#scheduleScrollBind();
        const onClose = () => this.#detach();

        frame.on('content:render', onRender);
        frame.on('close', onClose);
        this.#frameOff = [
            () => frame.off('content:render', onRender),
            () => frame.off('close', onClose),
        ];
    }

    #scheduleScrollBind() {
        // Wait for backbone to render the attachment grid before looking for the container.
        setTimeout(() => this.#bindScroll(), 200);
    }

    #bindScroll() {
        this.#unbindScroll();
        const frame = this.#frameRef;
        if (!frame) return;

        const root = frame.el || frame.$el?.[0];
        if (!root) return;

        // Помечаем frame root: init() запускается только при включённой опции infiniteScroll
        // (см. init), поэтому класс появляется лишь когда прокрутка активна. CSS по этому классу
        // прячет нативную WP-кнопку «Load more» — при infinite scroll нужен один механизм подгрузки
        // ([internal]). Скрытие кнопки не ломает догрузку: #loadMore видит offsetParent===null и
        // переключается на library.more() (backbone API). Работает и в grid, и в модалке — root есть
        // в обоих контекстах, в отличие от admin-body-класса (модалка вне should_render_static_shell).
        root.classList.add('plathix-infinite-active');
        this.#rootEl = root;
        this.#spinner = getSpinnerHandle(frame, root);

        // Контейнеры со своим скроллом — модальный пикер медиа (там прокручивается
        // внутренний .attachments-wrapper / .media-frame-content, а не окно).
        const containers = [
            root.querySelector('.attachments-wrapper'),
            root.querySelector('.media-frame-content'),
            root.querySelector('.attachments-browser'),
        ].filter(Boolean);

        // Проверка «доскроллил достаточно близко к низу» для контейнера с собственным
        // скроллом — единая формула isNearBottom (см. выше), не своя ветка логики.
        const onContainerScroll = () => {
            for (const container of containers) {
                const { scrollTop, scrollHeight, clientHeight } = container;
                if (isNearBottom(scrollTop, clientHeight, scrollHeight)) {
                    this.#loadMore(root);
                    return;
                }
            }
        };

        // Проверка «доскроллил достаточно близко к низу» для прокрутки ОКНА. На
        // upload.php (grid-режим WP-медиатеки) перечисленные выше контейнеры имеют
        // overflow:visible и растут по высоте вместе со страницей — сами НЕ скроллятся,
        // прокручивается документ. Без этого источника событие scroll не приходит и
        // автодогрузка не срабатывает (нативную кнопку «Load more» приходится жать
        // вручную). Та же формула isNearBottom, что и для контейнера — единый контракт.
        const onWindowScroll = () => {
            const doc = document.documentElement;
            if (isNearBottom(window.scrollY, window.innerHeight, doc.scrollHeight)) {
                this.#loadMore(root);
            }
        };

        const cleanup = [];
        containers.forEach((container) => {
            container.addEventListener('scroll', onContainerScroll, { passive: true });
            cleanup.push(() => container.removeEventListener('scroll', onContainerScroll));
        });
        window.addEventListener('scroll', onWindowScroll, { passive: true });
        cleanup.push(() => window.removeEventListener('scroll', onWindowScroll));

        this.#scrollOff = cleanup;
    }

    #loadMore(root) {
        if (this.#loading) return;
        const library = resolveLibrary();
        const loadMoreButton = root?.querySelector?.('.load-more');
        const canUseButton = loadMoreButton
            && !loadMoreButton.disabled
            && loadMoreButton.offsetParent !== null;
        if (!canUseButton && !library?.hasMore?.()) return;

        this.#loading = true;
        // Показ синхронно со стартом запроса (тем же вызовом, что #loading=true), не
        // после ответа сервера — иначе индикатор не успевает объяснить паузу пользователю
        // (acceptance criterion, wp-ux-design skeptic, [internal] spec).
        this.#spinner?.show();
        const done = () => {
            this.#loading = false;
            this.#spinner?.hide();
        };

        if (canUseButton) {
            loadMoreButton.click();
            setTimeout(done, 1200);
            return;
        }

        const deferred = library.more();
        if (deferred?.always) {
            deferred.always(done);
        } else {
            setTimeout(done, 2000);
        }
    }

    #unbindScroll() {
        this.#scrollOff.forEach((fn) => fn());
        this.#scrollOff = [];
        this.#loading = false;
    }

    #detach() {
        this.#unbindScroll();
        // Fallback spinner — собственный DOM-элемент, убираем явно, чтобы не копился при
        // повторных attach/detach (открыть модалку → закрыть → открыть снова). Нативный
        // spinner (toolbar-путь) принадлежит backbone view и не наш DOM для удаления.
        this.#rootEl?.querySelector(':scope > .plathix-infinite-scroll-spinner')?.remove();
        this.#spinner = null;
        this.#rootEl?.classList?.remove('plathix-infinite-active');
        this.#rootEl = null;
        this.#frameOff.forEach((fn) => fn());
        this.#frameOff = [];
        this.#frameRef = null;
    }
}

export const infiniteScrollManager = new InfiniteScrollManager();
