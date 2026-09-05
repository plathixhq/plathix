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

        // [internal] ([internal]): текст перевода экранируется escapeAttr(JSON.stringify(...))
        // вместо голой интерполяции в одинарных кавычках — проверяем через реальный
        // HTML-парсинг + Alpine-подобный eval, что @click остаётся валидным выражением
        // и alertMessage получает корректное значение перевода.
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

    // [internal]: разметка пикера цвета УЕХАЛА из overlays в модуль color/
    // (color-entry.js самомонтирует её в слот plathix-context-menu-items через insertOrdered
    // ORDER=30 — ниже PRO Gallery/ZIP, но выше «Удалить»). В шаблоне overlays её больше НЕТ.
    it('color picker markup no longer lives in overlays (moved to color/ module)', () => {
        const template = overlaysTemplate();
        expect(template).not.toContain('class="plathix-context-menu__color"');
        expect(template).not.toContain('x-data="colorPicker"');
    });

    // [internal]: топ-слот стоит ДО первого hr — «Избранное» монтируется первым пунктом.
    it('has top slot before the first separator for favorites to mount first', () => {
        const template = overlaysTemplate();
        const topSlotIdx = template.indexOf('data-slot="plathix-context-menu-top"');
        const firstHrIdx = template.indexOf('plathix-context-menu__separator"');
        expect(topSlotIdx).toBeGreaterThan(-1);
        expect(topSlotIdx).toBeLessThan(firstHrIdx);
    });

    // Слот и «Удалить» остаются в overlays; слот ПЕРЕД «Удалить» — модуль монтирует цвет
    // между ними (ORDER=30). HARD-инвариант «Цвет над Удалить» проверяется на стенде (Playwright),
    // т.к. это runtime-позиция самомонтируемого узла, а не статика шаблона.
    it('keeps the neutral slot before delete for the color module to mount between', () => {
        const template = overlaysTemplate();
        const slotIdx = template.indexOf('data-slot="plathix-context-menu-items"');
        const deleteIdx = template.indexOf('plathix-context-menu__danger');
        expect(slotIdx).toBeGreaterThan(-1);
        expect(deleteIdx).toBeGreaterThan(slotIdx);
    });
});

describe('overlaysTemplate — bulk-delete nested-folders warning ([internal])', () => {
    it('renders the warning variant class instead of inline color/background/border-color', () => {
        const html = overlaysTemplate();

        // Класс-модификатор присутствует в разметке (заменяет прежний inline style
        // color:#996800;background:#fffaeb;border-color:#f0c050 на overlays.js:90).
        expect(html).toContain('plathix-delete__safe--warning');

        // Убедиться, что inline-стиль реально убран с этого конкретного блока, а не
        // просто добавлен класс поверх старого style-атрибута.
        expect(html).not.toMatch(/style="color:#996800/);
    });
});
