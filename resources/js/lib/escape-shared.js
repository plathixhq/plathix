/**
 * Публикует escapeHtml/escapeAttr как window.PlathixEscape для PRO-потребителей через
 * runtime WP script dependency ([internal]) — не импортируется, отдельный webpack entry:
 * PRO webpack build не видит Free-исходники (см. spec/_done/[internal]),
 * функция публикуется как глобал, читаемый через wp_enqueue_script-зависимость на хендл
 * plathix-escape-shared, не как модуль.
 */
window.PlathixEscape = window.PlathixEscape || {};

/**
 * Экранирует HTML-спецсимволы в строке для безопасной вставки через x-html.
 *
 * @param {unknown} str
 * @returns {string}
 */
window.PlathixEscape.escapeHtml = function (str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

/**
 * Экранирует строку для безопасной вставки в HTML-атрибут.
 *
 * @param {unknown} str
 * @returns {string}
 */
window.PlathixEscape.escapeAttr = function (str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};
