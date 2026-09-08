import Alpine from 'alpinejs';
import { SidebarResizer } from './resize.js';
import { sidebarMarkup } from './templates/sidebar.js';
import { STATIC_ROOT_ID, MODAL_ROOT_ID, getStaticSidebarRoot } from './dom.js';
import { getPostType, getRuntime } from './runtime.js';
import { onMediaFrameReady } from './media-frame-watcher.js';

function applySkinClasses(rootEl) {
    const classes = getRuntime().skinClasses;
    if (Array.isArray(classes) && classes.length) {
        const sidebar = rootEl.querySelector('.plathix-sidebar') ?? rootEl;
        sidebar.classList.add(...classes);
    }
}

export function ensureStaticRoot() {
    const runtime = getRuntime();






    if (runtime.screenKind !== 'static') {
        return null;
    }
    if (runtime.mediaModalOnly) {
        return null;
    }
    const wpbody = document.querySelector('#wpbody');
    if (!wpbody) return null;

    // PHP may have pre-rendered an empty placeholder to reserve sidebar space.
    // If so, fill it with content instead of creating a new element.
    let wrapper = getStaticSidebarRoot();
    if (!wrapper) {
        wrapper = document.createElement('div');
        wrapper.id = STATIC_ROOT_ID;
    }

    if (wrapper.parentElement !== wpbody) {
        const content = document.getElementById('wpbody-content');
        if (content) {
            wpbody.insertBefore(wrapper, content);
        } else {
            wpbody.insertAdjacentElement('afterbegin', wrapper);
        }
    }

    if (!wrapper.children.length) {
        wrapper.innerHTML = sidebarMarkup();
        applySkinClasses(wrapper);
    }

    const _saved = SidebarResizer.loadState(getPostType());
    if (_saved.collapsed) {


        wrapper.classList.add('is-collapsed');
    } else if (_saved.width >= 320) {
        wrapper.style.width = _saved.width + 'px';
    }

    wpbody.classList.add('plathix-body-sidebar');
    document.body.classList.remove('plathix-sidebar-shell');
    document.body.classList.add('plathix-static-ready');
    return wrapper;
}

export class MountManager {
    #frame = null;
    #frameEl = null;
    #mounted = false;
    #bootstrapped = false;
    #mountRetryTimer = null;
    #mountRetryCount = 0;
    #listeners = [];
    #observer = null;

    mount() {
        if (!getRuntime().mediaModalOnly) {
            return;
        }

        if (this.#bootstrapped) {
            return;
        }

        this.#bootstrapped = true;




        onMediaFrameReady((frame) => this.#onOpen(frame));

        if (this.#resolveFrameElement()) {
            this.#ensureMounted();
        }
    }

    #onOpen(frame) {
        this.#mountRetryCount = 0;
        this.#frame = frame;
        this.#frameEl = this.#resolveFrameElement();
        this.#bindFrameEvents();
        this.#bindPersistentOpenListener(frame);
        this.#ensureMounted();
    }

    

    #bindPersistentOpenListener(frame) {
        if (frame.__plathixOpenBound) {
            return;
        }
        frame.__plathixOpenBound = true;
        frame.on('open', () => this.#ensureMounted());
    }

    #resolveFrameElement() {
        const frameEl = this.#frame?.el || this.#frame?.$el?.[0] || this.#frameEl;
        if (frameEl instanceof HTMLElement) {
            return frameEl;
        }

        const fallback = document.querySelector('.media-frame');
        return fallback instanceof HTMLElement ? fallback : null;
    }

    #ensureMounted() {
        const mediaFrame = this.#resolveFrameElement();
        if (!mediaFrame) {





            this.#mountRetryCount++;
            if (this.#mountRetryCount < 5) {
                this.#queueEnsureMounted();
            } else {
                this.#clearEnsureMountedRetry();
            }
            return;
        }

        this.#frameEl = mediaFrame;

        let menuPanel = mediaFrame.querySelector('.media-frame-menu');
        if (!menuPanel) {
            this.#mountRetryCount++;
            // Wait briefly for async frame rendering. Once .media-frame-content
            // is present the frame is ready; if .media-frame-menu still doesn't
            // exist we're in a Select-type frame (e.g. Elementor) that never
            // renders a navigation panel — create a synthetic one so the sidebar
            // can still mount.
            const frameIsReady = !!mediaFrame.querySelector('.media-frame-content');
            if (!frameIsReady || this.#mountRetryCount < 5) {
                this.#queueEnsureMounted();
                return;
            }
            menuPanel = document.createElement('div');
            menuPanel.className = 'media-frame-menu';
            const syntheticMenu = document.createElement('ul');
            syntheticMenu.className = 'media-menu';
            menuPanel.appendChild(syntheticMenu);
            mediaFrame.prepend(menuPanel);
        }

        const menuInner = menuPanel.querySelector('.media-menu');
        if (!menuInner) {




            this.#mountRetryCount++;
            if (this.#mountRetryCount < 5) {
                this.#queueEnsureMounted();
            } else {
                this.#clearEnsureMountedRetry();
            }
            return;
        }

        this.#clearEnsureMountedRetry();

        let root = menuInner.querySelector(`#${MODAL_ROOT_ID}`);

        if (!root) {
            const wrapper = document.createElement('div');
            wrapper.id = MODAL_ROOT_ID;
            wrapper.innerHTML = sidebarMarkup();
            applySkinClasses(wrapper);
            menuInner.appendChild(wrapper);
            root = wrapper;
            this.#mounted = false;

            mediaFrame.classList.add('plathix-media-sidebar');
            menuPanel.classList.add('plathix-modal-panel');
            menuInner.classList.add('plathix-mounted');
        } else {
            mediaFrame.classList.add('plathix-media-sidebar');
            menuPanel.classList.add('plathix-modal-panel');
            menuInner.classList.add('plathix-mounted');
        }

        if (this.#mounted && root._x_dataStack) {
            Alpine.store('plathix').resetTransientState?.();
            return;
        }

        Alpine.initTree(root);
        this.#mounted = true;
        root.dataset.wcMountCount = String((Number(root.dataset.wcMountCount) || 0) + 1);
    }

    #queueEnsureMounted() {
        if (this.#mountRetryTimer !== null) {
            return;
        }

        this.#mountRetryTimer = window.setTimeout(() => {
            this.#mountRetryTimer = null;
            this.#ensureMounted();
        }, 50);
    }

    #clearEnsureMountedRetry() {
        this.#mountRetryCount = 0;
        if (this.#mountRetryTimer === null) {
            return;
        }

        window.clearTimeout(this.#mountRetryTimer);
        this.#mountRetryTimer = null;
    }

    #bindFrameEvents() {
        this.#teardownFrameListeners();

        const onClose = () => {
            Alpine.store('plathix').cleanup?.();
            this.#teardownFrameListeners();
            this.#frame = null;
            this.#frameEl = null;
            this.#teardownMountedFrame();



        };
        const onContentRender = () => this.#ensureMounted();
        const onRouterRender = () => this.#ensureMounted();

        this.#frame.on('close', onClose);
        this.#frame.on('content:render', onContentRender);
        this.#frame.on('router:render', onRouterRender);

        this.#listeners.push(
            { target: this.#frame, event: 'close', handler: onClose },
            { target: this.#frame, event: 'content:render', handler: onContentRender },
            { target: this.#frame, event: 'router:render', handler: onRouterRender },
        );

        const frameEl = this.#resolveFrameElement();
        if (frameEl && !this.#observer) {
            this.#observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    for (const node of mutation.removedNodes) {
                        if (/** @type {Element} */ (node).id === MODAL_ROOT_ID) {
                            this.#mounted = false;
                            this.#ensureMounted();
                            return;
                        }
                    }
                }
            });
            this.#observer.observe(frameEl, { childList: true, subtree: true });
        }
    }

    #teardownMountedFrame() {
        const frameEl = this.#resolveFrameElement();
        if (frameEl instanceof HTMLElement) {
            frameEl.classList.remove('plathix-media-sidebar');
        }

        const menuPanel = frameEl?.querySelector?.('.media-frame-menu');
        if (menuPanel instanceof HTMLElement) {
            menuPanel.classList.remove('plathix-modal-panel');
        }

        const menuInner = frameEl?.querySelector?.('.media-menu');
        if (menuInner instanceof HTMLElement) {
            menuInner.classList.remove('plathix-mounted');
        }

        frameEl?.querySelector?.(`#${MODAL_ROOT_ID}`)?.remove();
        this.#mounted = false;
        this.#clearEnsureMountedRetry();
    }

    #teardownFrameListeners() {
        this.#listeners.forEach(({ target, event, handler }) => target.off(event, handler));
        this.#listeners = [];
        if (this.#observer) {
            this.#observer.disconnect();
            this.#observer = null;
        }
        this.#clearEnsureMountedRetry();
    }
}
