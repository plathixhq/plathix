import { getRuntime } from './runtime.js';
import { onMediaFrameReady } from './media-frame-watcher.js';







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








function getSpinnerHandle(frame, root) {
    const nativeSpinner = frame?.content?.get?.()?.toolbar?.get?.('spinner');
    if (nativeSpinner?.show && nativeSpinner?.hide) {
        return { show: () => nativeSpinner.show(), hide: () => nativeSpinner.hide() };
    }



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
    #rootEl = null;
    #spinner = null;

    init() {
        if (!getRuntime().infiniteScroll) return;
        if (this.#initialized) return;
        this.#initialized = true;

        // Modal context: listen for any wp.media frame opening.


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







        root.classList.add('plathix-infinite-active');
        this.#rootEl = root;
        this.#spinner = getSpinnerHandle(frame, root);



        const containers = [
            root.querySelector('.attachments-wrapper'),
            root.querySelector('.media-frame-content'),
            root.querySelector('.attachments-browser'),
        ].filter(Boolean);



        const onContainerScroll = () => {
            for (const container of containers) {
                const { scrollTop, scrollHeight, clientHeight } = container;
                if (isNearBottom(scrollTop, clientHeight, scrollHeight)) {
                    this.#loadMore(root);
                    return;
                }
            }
        };







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
