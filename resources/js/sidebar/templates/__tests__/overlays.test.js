jest.mock('../../events.js', () => ({
    Events: {
        CONTEXT_MENU: 'plathix:context-menu',
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { overlaysTemplate } from '../overlays.js';

describe('overlays template', () => {
    it('keeps create subfolder button visible but disabled at max depth', () => {
        const template = overlaysTemplate();

        expect(template).toContain(':aria-disabled="(!$store.plathix.canCreateChild($store.plathix.getFolderDepth(folder?.id))).toString()"');
        expect(template).toContain('Maximum nesting depth reached');
        expect(template).toContain('New subfolder');





        const container = document.createElement('div');
        container.innerHTML = template;
        let button = container.querySelector('[data-action="new-subfolder"]');
        if (!button) {
            for (const tmpl of container.querySelectorAll('template')) {
                button = tmpl.content.querySelector('[data-action="new-subfolder"]');
                if (button) break;
            }
        }
        const clickAttr = button.getAttribute('@click');
        const store = { plathix: { canCreateChild: () => false, getFolderDepth: () => 5, alertMessage: null } };
        const close = jest.fn();
        const createSubfolder = jest.fn();
        // eslint-disable-next-line no-new-func
        const evaluate = new Function('$store', 'close', 'createSubfolder', 'folder', 'return ' + clickAttr);
        evaluate(store, close, createSubfolder, { id: 5 });
        expect(close).toHaveBeenCalled();
        expect(createSubfolder).not.toHaveBeenCalled();
        expect(store.plathix.alertMessage).toBe('Maximum nesting depth reached');
    });




    it('color picker markup no longer lives in overlays (moved to color/ module)', () => {
        const template = overlaysTemplate();
        expect(template).not.toContain('class="plathix-context-menu__color"');
        expect(template).not.toContain('x-data="colorPicker"');
    });


    it('has top slot before the first separator for favorites to mount first', () => {
        const template = overlaysTemplate();
        const topSlotIdx = template.indexOf('data-slot="plathix-context-menu-top"');
        const firstHrIdx = template.indexOf('plathix-context-menu__separator"');
        expect(topSlotIdx).toBeGreaterThan(-1);
        expect(topSlotIdx).toBeLessThan(firstHrIdx);
    });




    it('keeps the neutral slot before delete for the color module to mount between', () => {
        const template = overlaysTemplate();
        const slotIdx = template.indexOf('data-slot="plathix-context-menu-items"');
        const deleteIdx = template.indexOf('plathix-context-menu__danger');
        expect(slotIdx).toBeGreaterThan(-1);
        expect(deleteIdx).toBeGreaterThan(slotIdx);
    });
});

describe('handles trash workflow consistently', () => {
    it('renders the warning variant class instead of inline color/background/border-color', () => {
        const html = overlaysTemplate();



        expect(html).toContain('plathix-delete__safe--warning');



        expect(html).not.toMatch(/style="color:#996800/);
    });
});
