const HEX_RE = /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/;

/**
 * Показ цвета папки — реальная реализация, домёрживаемая модулем color/ в store
 * ([internal], форма v4). Без модуля store держит stub (folderColorStyle→'',
 * folderColorFill→'none'), поэтому папки серые при отсутствии фичи (семантика ВАРИАНТ А:
 * при выносе в PRO и отключении — цвет пропадает с экрана, данные в term-meta целы).
 *
 * safeHexColor перенесён сюда из sidebar/color-utils.js (фича цвета целиком в модуле).
 */
export function safeHexColor(color) {
    return typeof color === 'string' && HEX_RE.test(color) ? color : null;
}

/** Методы показа, которыми модуль домёрживает store (реальная реализация поверх stub). */
export const colorShowImpl = {
    folderColorStyle(folder) {
        const c = safeHexColor(folder?.color);
        return c ? 'color:' + c : '';
    },
    folderColorFill(folder) {
        const c = safeHexColor(folder?.color);
        return c ? c + '33' : 'none';
    },
};
