jest.mock('../../events.js', () => ({
    Events: {},
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import { treeLevel, treeLevelMarkup } from '../folder-tree.js';

// [internal]/202: дерево больше НЕ пре-генерирует строку на N уровней глубины.
// treeLevelMarkup() возвращает РОВНО ОДИН уровень; вложенные уровни монтируются рантаймом
// Alpine через x-html (рекурсия при инстанцировании, не в строке). Нет depth-параметра,
// нет TEMPLATE_MAX_DEPTH-потолка, нет обрезания — глубина DOM = фактической глубине данных
// (unlimited соблюдён). Рантайм-рекурсию (реальное монтирование Alpine) проверяет browser
// proof на стенде ([internal]) — unit проверяет только форму строки одного уровня.

describe('folder-tree template — один уровень (без пре-генерации глубины)', () => {
    it('treeLevelMarkup генерирует ровно ОДИН уровень веток', () => {
        const html = treeLevelMarkup();
        // один x-for по folders + одна ветка-шаблон
        expect(html).toContain('x-for="folder in folders"');
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
    });

    it('место детей — рекурсивный x-html, а НЕ статически развёрнутый второй уровень', () => {
        const html = treeLevelMarkup();
        // точка рантайм-рекурсии присутствует
        expect(html).toContain('x-html="treeLevelHtml()"');
        // вложенный контейнер детей — свой folderTree-scope
        expect(html).toContain('class="plathix-folder__children"');
        expect(html).toContain('x-data="folderTree"');
    });

    it('КОНТРОЛЬ-«убитый»: НЕТ статически пре-развёрнутого второго уровня внутри детей', () => {
        const html = treeLevelMarkup();
        // если пре-генерация вернётся, внутри .plathix-folder__children появится второй
        // plathix-folder-branch/plathix-tree-level статической строкой → счётчик > 1.
        // Сейчас детей рендерит x-html в рантайме, поэтому в строке ровно один уровень.
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
        expect((html.match(/x-for="folder in folders"/g) || []).length).toBe(1);
    });

    it('содержит форму создания папки на своём уровне (newFolderForm)', () => {
        const html = treeLevelMarkup();
        expect(html).toContain('plathix-new-folder__form');
    });
});

describe('folder-tree template — обёртка уровня treeLevel', () => {
    it('оборачивает один уровень в собственный folderTree-scope с parentId', () => {
        const html = treeLevel('folder.id');
        expect(html).toContain('class="plathix-tree-level"');
        expect(html).toContain('x-data="folderTree"');
        expect(html).toContain('parentId = Number(folder.id)');
        // и внутри — ровно один уровень (не матрёшка)
        expect((html.match(/plathix-folder-branch/g) || []).length).toBe(1);
    });

    it('НЕ принимает depth-параметр и НЕ обрезает по глубине (unlimited, потолок убран)', () => {
        // прежний контракт treeLevel(parent, depth) с обрезанием depth>limit удалён;
        // вызов без второго аргумента всегда даёт один уровень, пустой строки на «глубине» нет.
        expect(treeLevel('0')).toContain('plathix-tree-level');
        expect(treeLevel('folder.id')).toContain('plathix-tree-level');
    });
});
