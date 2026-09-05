import { toolbarTemplate } from './toolbar.js';
import { userTreeTemplate } from './user-tree.js';
import { footerTemplate } from './footer.js';
import { overlaysTemplate } from './overlays.js';

export function sidebarMarkup() {
    return `
        <div class="plathix-sidebar" x-data="bulkActions">
            ${toolbarTemplate()}
            <hr class="plathix-block__divider">
            <div data-slot="plathix-favorites"></div>
            ${userTreeTemplate()}
            ${overlaysTemplate()}
            ${footerTemplate()}
        </div>
    `;
}
