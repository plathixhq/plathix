/**
 * @param {PlathixI18nKey} key
 * @param {string} fallback
 * @returns {string}
 */
export const t = (key, fallback) => window.Plathix?.i18n?.[key] || fallback;
