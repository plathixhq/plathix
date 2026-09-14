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

jest.mock('../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../store/search.js', () => ({
    searchModule: {},
}));

import { searchWrapHTML } from '../search-entry.js';



describe('escapes untrusted output for the destination context', () => {
    it('renders ordinary i18n strings unchanged (baseline)', () => {
        const html = searchWrapHTML();

        expect(html).toContain('placeholder="Search folders"');
        expect(html).toContain('title="Sort"');
        expect(html).toContain('<div class="plathix-sort__header">SORT</div>');
        expect(html).toContain('<span>By default</span>');
    });

    it('escapes a double-quote payload in the placeholder attribute (does not break out of the attribute)', () => {
        jest.resetModules();
        jest.doMock('../i18n.js', () => ({
            t: (key) => (key === 'search_folders' ? 'x" onmouseover="alert(1)' : key),
        }));
        // eslint-disable-next-line global-require
        const { searchWrapHTML: freshSearchWrapHTML } = require('../search-entry.js');

        const html = freshSearchWrapHTML();
        const container = document.createElement('div');
        container.innerHTML = html;
        const input = container.querySelector('.plathix-search__input');

        expect(input.getAttribute('placeholder')).toBe('x" onmouseover="alert(1)');
        expect(input.getAttribute('onmouseover')).toBeNull();

        jest.dontMock('../i18n.js');
    });

    it('escapes HTML special characters in the text-node sort strings', () => {
        jest.resetModules();
        jest.doMock('../i18n.js', () => ({
            t: (key) => (key === 'sort_default' ? '<img src=x onerror=alert(1)>' : key),
        }));
        // eslint-disable-next-line global-require
        const { searchWrapHTML: freshSearchWrapHTML } = require('../search-entry.js');

        const html = freshSearchWrapHTML();

        expect(html).not.toContain('<img src=x onerror=alert(1)>');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');

        jest.dontMock('../i18n.js');
    });
});
