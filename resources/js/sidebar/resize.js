import { getStaticSidebarRoot } from './dom.js';

export class SidebarResizer {
    static _key(postType) {
        return 'plathix_sidebar_' + postType;
    }

    static loadState(postType) {
        try {
            return JSON.parse(localStorage.getItem(SidebarResizer._key(postType)) || '{}');
        } catch {
            return {};
        }
    }

    constructor(postType) {
        this._postType = postType;
        this._minWidth = 320;
        this._maxWidth = 800;

        const saved = SidebarResizer.loadState(postType);
        this._width = Math.max(this._minWidth, Number(saved.width) || this._minWidth);
        this._collapsed = !!saved.collapsed;

        this._handle = null;
        this._tab = null;
        this._resizeHandler = null;
        this._scrollHandler = null;
        this._init();
    }

    _init() {
        this._handle = document.createElement('div');
        this._handle.className = 'plathix-resize__handle';
        document.body.appendChild(this._handle);

        this._tab = document.createElement('button');
        this._tab.type = 'button';
        this._tab.className = 'plathix-collapse__tab';
        this._tab.setAttribute('aria-label', 'Toggle sidebar');
        document.body.appendChild(this._tab);

        this._applyToRoot();
        this._bindResize();
        this._bindToggle();
        this._resizeHandler = () => this._updatePositions();
        this._scrollHandler = () => this._updatePositions();
        window.addEventListener('resize', this._resizeHandler);
        window.addEventListener('scroll', this._scrollHandler, { passive: true });
    }

    _root() {
        return getStaticSidebarRoot();
    }

    _applyToRoot() {
        const root = this._root();
        if (!root) return;

        if (this._collapsed) {
            // width:0/margin-right:0 живут в CSS (.is-collapsed, resources/css/sidebar.css) —
            // [internal]. Явный сброс inline обязателен: expanded-ветка (или live
            // drag) уже могла записать root.style.width/marginRight ранее — inline
            // (specificity 1,0,0,0) перебивает CSS-класс независимо от composite-селектора,
            // класс молча проигрывает если inline не очищен (найдено browser-proof).
            root.classList.add('is-collapsed');
            root.style.width = '';
            root.style.marginRight = '';
        } else {
            root.classList.remove('is-collapsed');
            // Dynamic: this._width меняется в рантайме (resize/localStorage), не выносится.
            root.style.width = this._width + 'px';
            root.style.marginRight = '';
        }

        if (this._tab) {
            this._tab.textContent = this._collapsed ? '›' : '‹';
            this._tab.classList.toggle('is-collapsed', this._collapsed);
        }
        requestAnimationFrame(() => this._updatePositions());
    }

    _updatePositions() {
        const root = this._root();
        if (!root) return;

        const left = root.getBoundingClientRect().left;
        const edge = this._collapsed ? left - 20 : left + this._width;

        if (this._handle) {
            this._handle.style.left = (edge - 3) + 'px';
            // [internal]: было .style.display напрямую — переиспользует
            // this._collapsed, тот же флаг, что уже двигает .is-collapsed на root
            // (_applyToRoot), не параллельный источник истины.
            this._handle.classList.toggle('is-collapse-hidden', this._collapsed);
        }

        if (this._tab) {
            this._tab.style.left = edge + 'px';
            this._tab.textContent = this._collapsed ? '›' : '‹';
        }
    }

    _save() {
        try {
            localStorage.setItem(
                SidebarResizer._key(this._postType),
                JSON.stringify({ width: this._width, collapsed: this._collapsed })
            );
        } catch {}
    }

    toggle() {
        this._collapsed = !this._collapsed;
        this._applyToRoot();
        this._save();
    }

    _bindResize() {
        if (!this._handle) return;
        const COLLAPSE_THRESHOLD = 50;

        this._handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const root = this._root();
            if (!root) return;

            root.classList.add('is-resizing');
            // Static cursor/user-select during drag — [internal], класс на body.
            document.body.classList.add('plathix-resizing');

            const startX = e.clientX;
            const startWidth = this._width;
            let collapseOnRelease = false;

            const onMove = (e) => {
                const raw = startWidth + e.clientX - startX;

                if (raw < this._minWidth - COLLAPSE_THRESHOLD) {
                    collapseOnRelease = true;
                    this._tab?.classList.add('is-collapse-hint');
                    root.style.width = this._minWidth + 'px';
                } else if (raw < this._minWidth) {
                    collapseOnRelease = false;
                    this._tab?.classList.remove('is-collapse-hint');
                    root.style.width = this._minWidth + 'px';
                } else {
                    collapseOnRelease = false;
                    this._tab?.classList.remove('is-collapse-hint');
                    this._width = Math.min(this._maxWidth, raw);
                    root.style.width = this._width + 'px';
                    this._updatePositions();
                }
            };

            const onUp = () => {
                this._tab?.classList.remove('is-collapse-hint');
                root.classList.remove('is-resizing');
                document.body.classList.remove('plathix-resizing');

                if (collapseOnRelease) {
                    this._collapsed = true;
                    this._applyToRoot();
                } else {
                    this._width = Math.max(this._minWidth, parseInt(root.style.width, 10) || this._minWidth);
                    this._updatePositions();
                }

                this._save();
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    _bindToggle() {
        if (!this._tab) return;
        this._tab.addEventListener('click', () => this.toggle());
    }

    destroy() {
        if (this._resizeHandler) {
            window.removeEventListener('resize', this._resizeHandler);
            this._resizeHandler = null;
        }
        if (this._scrollHandler) {
            window.removeEventListener('scroll', this._scrollHandler);
            this._scrollHandler = null;
        }
        this._handle?.remove();
        this._tab?.remove();
    }
}
