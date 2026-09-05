import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * Контракт search-модуля ([internal]). search-entry.js — entry-бандл,
 * ничего не экспортирует (выполняется при загрузке), поэтому проверяем source-scan'ом.
 * debounce-контракт инпута переехал сюда из toolbar.test.js при выносе search в модуль.
 */
describe('search-entry source contract', () => {
    const source = readFileSync(join(__dirname, '..', 'search-entry.js'), 'utf8');

    it('keeps the search input debounce contract', () => {
        // Тот самый контракт, что раньше жил в toolbar.js: debounce 500ms → setSearchQuery.
        expect(source).toContain('@input.debounce.500ms="$store.plathix.setSearchQuery($event.target.value)"');
    });

    it('mounts into the toolbar search slot', () => {
        // search-entry вставляет инпут в слот, который отдаёт toolbar (data-slot="plathix-search").
        expect(source).toContain('[data-slot="plathix-search"]');
    });
});
