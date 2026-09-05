import { SidebarResizer } from './resize.js';
import { ensureStaticRoot } from './mount-manager.js';
import { getPostType, isStaticScreen } from './runtime.js';
import { getStateValue, setStateValue } from './state.js';

export function bootstrapStaticSidebar() {
    if (!isStaticScreen()) {
        return;
    }

    const root = ensureStaticRoot();
    if (!root) {
        document.body.classList.remove('plathix-sidebar-shell');
        document.body.classList.add('plathix-static-ready');
        return;
    }

    getStateValue('sidebarResizer')?.destroy?.();
    setStateValue('sidebarResizer', new SidebarResizer(getPostType()));
}
