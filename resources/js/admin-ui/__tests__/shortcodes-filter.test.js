import { rowMatchesShortcodeFilters, initShortcodesFilter } from '../shortcodes-filter.js';

describe('shortcodes table AND filtering', () => {
    const row = { type: 'page', status: 'publish', folders: ['Sale', 'Hero'], title: 'Landing page' };

    it('matches when no filters are set', () => {
        expect(rowMatchesShortcodeFilters(row, {})).toBe(true);
    });

    it('matches when type filter equals row type', () => {
        expect(rowMatchesShortcodeFilters(row, { type: 'page' })).toBe(true);
    });

    it('excludes when type filter differs', () => {
        expect(rowMatchesShortcodeFilters(row, { type: 'product' })).toBe(false);
    });

    it('matches folder when present in row folders array', () => {
        expect(rowMatchesShortcodeFilters(row, { folder: 'Hero' })).toBe(true);
    });

    it('excludes folder not in row folders', () => {
        expect(rowMatchesShortcodeFilters(row, { folder: 'Missing' })).toBe(false);
    });

    it('combines type AND folder — both must pass', () => {
        expect(rowMatchesShortcodeFilters(row, { type: 'page', folder: 'Sale' })).toBe(true);
        // тип проходит, папка нет → строка скрыта (И)
        expect(rowMatchesShortcodeFilters(row, { type: 'page', folder: 'Missing' })).toBe(false);
        // папка проходит, тип нет → строка скрыта (И)
        expect(rowMatchesShortcodeFilters(row, { type: 'product', folder: 'Sale' })).toBe(false);
    });

    it('combines status into AND chain', () => {
        expect(rowMatchesShortcodeFilters(row, { type: 'page', status: 'publish', folder: 'Sale' })).toBe(true);
        expect(rowMatchesShortcodeFilters(row, { status: 'draft' })).toBe(false);
    });

    it('search matches title case-insensitively', () => {
        expect(rowMatchesShortcodeFilters(row, { search: 'LANDING' })).toBe(true);
        expect(rowMatchesShortcodeFilters(row, { search: 'nope' })).toBe(false);
    });
});

describe('initShortcodesFilter — DOM wiring and no-results row', () => {
    function buildTable() {
        document.body.innerHTML = `
            <select id="plathix-filter-type"><option value=""></option><option value="page">page</option><option value="product">product</option></select>
            <input id="plathix-filter-search" />
            <table id="plathix-shortcodes-table"><tbody>
                <tr data-type="page" data-status="publish" data-folders='["Sale"]' data-title="landing page"><td>A</td></tr>
                <tr data-type="product" data-status="publish" data-folders='["Hero"]' data-title="shop item"><td>B</td></tr>
                <tr class="plathix-no-results is-hidden"><td>nothing</td></tr>
            </tbody></table>
        `;
    }

    // [internal]: было .style.display, теперь classList.toggle('is-hidden', ...).
    it('hides rows that do not match and keeps no-results hidden while something is visible', () => {
        buildTable();
        initShortcodesFilter(document);

        const typeEl = document.getElementById('plathix-filter-type');
        typeEl.value = 'page';
        typeEl.dispatchEvent(new Event('change'));

        const rows = document.querySelectorAll('tr[data-folders]');
        expect(rows[0].classList.contains('is-hidden')).toBe(false); // page → виден
        expect(rows[1].classList.contains('is-hidden')).toBe(true);  // product → скрыт
        expect(document.querySelector('.plathix-no-results').classList.contains('is-hidden')).toBe(true);
    });

    it('shows no-results row when filters hide every row', () => {
        buildTable();
        initShortcodesFilter(document);

        const searchEl = document.getElementById('plathix-filter-search');
        searchEl.value = 'zzz-no-match';
        searchEl.dispatchEvent(new Event('input'));

        const rows = document.querySelectorAll('tr[data-folders]');
        expect(rows[0].classList.contains('is-hidden')).toBe(true);
        expect(rows[1].classList.contains('is-hidden')).toBe(true);
        expect(document.querySelector('.plathix-no-results').classList.contains('is-hidden')).toBe(false);
    });
});
