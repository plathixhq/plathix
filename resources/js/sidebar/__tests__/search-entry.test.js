import { readFileSync } from 'fs';
import { join } from 'path';



describe('search-entry source contract', () => {
    const source = readFileSync(join(__dirname, '..', 'search-entry.js'), 'utf8');

    it('keeps the search input debounce contract', () => {

        expect(source).toContain('@input.debounce.500ms="$store.plathix.setSearchQuery($event.target.value)"');
    });

    it('mounts into the toolbar search slot', () => {

        expect(source).toContain('[data-slot="plathix-search"]');
    });
});
