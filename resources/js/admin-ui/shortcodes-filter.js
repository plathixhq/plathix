/**
 * Чистая логика фильтрации таблицы шорткодов (страница Шорткоды, [internal]).
 *
 * Все активные фильтры комбинируются по принципу И (AND): строка видна, только
 * если проходит каждый заданный фильтр. Пустой фильтр (значение '') не сужает.
 * Выделено в чистую функцию для unit-теста; навешивание на DOM — initShortcodesFilter.
 */

/**
 * @typedef {{ type?: string, status?: string, folders?: string[], title?: string }} ShortcodeRow
 * @typedef {{ type?: string, status?: string, folder?: string, search?: string }} ShortcodeFilters
 */

/**
 * Проходит ли строка все активные фильтры (логика И).
 *
 * @param {ShortcodeRow} row
 * @param {ShortcodeFilters} filters
 * @returns {boolean}
 */
export function rowMatchesShortcodeFilters(row, filters) {
    const type   = filters.type || '';
    const status = filters.status || '';
    const folder = filters.folder || '';
    const search = (filters.search || '').toLowerCase().trim();

    if (type && row.type !== type) {
        return false;
    }
    if (status && row.status !== status) {
        return false;
    }
    if (folder && !(Array.isArray(row.folders) && row.folders.includes(folder))) {
        return false;
    }
    if (search && !String(row.title || '').toLowerCase().includes(search)) {
        return false;
    }

    return true;
}

/**
 * Навешивает И-фильтрацию на таблицу шорткодов.
 * No-op, если на странице нет таблицы/фильтров.
 */
export function initShortcodesFilter(doc = document) {
    const typeEl   = doc.getElementById('plathix-filter-type');
    const statusEl = doc.getElementById('plathix-filter-status');
    const folderEl = doc.getElementById('plathix-filter-folder');
    const searchEl = doc.getElementById('plathix-filter-search');

    // Строки таблицы шорткодов несут data-type/status/folders/title.
    const rows = Array.from(doc.querySelectorAll('tr[data-folders]'));
    if (!rows.length) {
        return;
    }

    // Строка-заглушка «нет результатов» — показывается, когда фильтры скрыли всё.
    const noResults = doc.querySelector('#plathix-shortcodes-table .plathix-no-results');

    const apply = () => {
        const filters = {
            type:   typeEl ? typeEl.value : '',
            status: statusEl ? statusEl.value : '',
            folder: folderEl ? folderEl.value : '',
            search: searchEl ? searchEl.value : '',
        };

        let visible = 0;

        rows.forEach((row) => {
            let folders = [];
            try {
                folders = JSON.parse(row.getAttribute('data-folders') || '[]');
            } catch (e) {
                folders = [];
            }

            const match = rowMatchesShortcodeFilters(
                {
                    type:    row.getAttribute('data-type') || '',
                    status:  row.getAttribute('data-status') || '',
                    folders: Array.isArray(folders) ? folders : [],
                    title:   row.getAttribute('data-title') || '',
                },
                filters
            );

            // [internal]: было .style.display напрямую — общий state-класс
            // .is-hidden (resources/css/admin-ui.css).
            row.classList.toggle('is-hidden', !match);
            if (match) {
                visible += 1;
            }
        });

        if (noResults) {
            noResults.classList.toggle('is-hidden', visible !== 0);
        }
    };

    [typeEl, statusEl, folderEl].forEach((el) => el && el.addEventListener('change', apply));
    if (searchEl) {
        searchEl.addEventListener('input', apply);
    }
}
