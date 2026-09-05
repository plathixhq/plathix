import { SEL_LIST, SEL_TABLENAV_TOP, SEL_TABLENAV_BOTTOM, SEL_VIEWS } from './selectors.js';

export function extractZones(doc) {
    return {
        list:           doc.querySelector(SEL_LIST),
        tablenavTop:    doc.querySelector(SEL_TABLENAV_TOP),
        tablenavBottom: doc.querySelector(SEL_TABLENAV_BOTTOM),
        views:          doc.querySelector(SEL_VIEWS),
    };
}

export function safeReplace(live, incoming) {
    if (!incoming || !live?.parentNode) return false;
    live.parentNode.replaceChild(incoming, live);
    return true;
}

export function parseFragment(html) {
    const t = document.createElement('template');
    t.innerHTML = html;
    return t.content.firstElementChild || null;
}

/**
 * Достаёт из фрагмента элемент по СЕЛЕКТОРУ, а не первый дочерний.
 *
 * Нужен для tablenav: WP `WP_List_Table::display_tablenav('top')` выводит скрытые
 * inputs (`_wpnonce`, `_wp_http_referer`) ПЕРЕД `<div class="tablenav top">`. Поэтому
 * `firstElementChild` вернул бы `_wpnonce`-input, а не блок пагинации — и замена им
 * `.tablenav.top` уничтожала пагинацию навсегда ([internal]). querySelector берёт
 * именно нужный элемент. Возвращает null, если селектор не найден (тогда live-элемент
 * остаётся нетронутым — безопаснее, чем вставить неверный узел).
 *
 * @param {string} html
 * @param {string} selector
 * @returns {Element|null}
 */
export function parseFragmentBySelector(html, selector) {
    const t = document.createElement('template');
    t.innerHTML = html;
    return t.content.querySelector(selector) || null;
}
