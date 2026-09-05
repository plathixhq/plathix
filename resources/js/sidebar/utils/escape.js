/**
 * Экранирует HTML-спецсимволы в строке для безопасной вставки через x-html.
 *
 * @param {unknown} str
 * @returns {string}
 */
export function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Экранирует строку для безопасной вставки в HTML-атрибут ([internal], консолидация
 * дублирующих реализаций — было отдельно в trash-core.js, [internal] fix).
 *
 * @param {unknown} str
 * @returns {string}
 */
export function escapeAttr(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
