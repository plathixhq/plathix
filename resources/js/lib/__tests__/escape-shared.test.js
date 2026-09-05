import { escapeHtml as freeEscapeHtml, escapeAttr as freeEscapeAttr } from '../../sidebar/utils/escape.js';

// [internal], [internal]: contract-тест на сам факт корректной
// публикации window.PlathixEscape.escapeHtml/escapeAttr и поведенческую эквивалентность
// с Free-эталоном (resources/js/sidebar/utils/escape.js). escape-shared.js не экспортирует
// функции (публикует только через window) — require грузит файл ради побочного эффекта
// присвоения window.PlathixEscape, тот же паттерн, каким PRO позже читает этот хендл в
// браузере через wp_enqueue_script-зависимость.

const PROBE_STRINGS = [
    '&',
    '<',
    '>',
    '"',
    "'",
    '<script>alert(1)</script>',
    null,
    undefined,
    '',
    '&amp;',
    "it's a <b>test</b> & \"quote\"",
];

describe('escape-shared.js публикует window.PlathixEscape', () => {
    beforeAll(() => {
        delete window.PlathixEscape;
        require('../escape-shared.js');
    });

    it('window.PlathixEscape.escapeHtml и escapeAttr — функции', () => {
        expect(typeof window.PlathixEscape.escapeHtml).toBe('function');
        expect(typeof window.PlathixEscape.escapeAttr).toBe('function');
    });

    it.each(PROBE_STRINGS)('escapeHtml(%p) совпадает с Free-эталоном', (probe) => {
        expect(window.PlathixEscape.escapeHtml(probe)).toBe(freeEscapeHtml(probe));
    });

    it.each(PROBE_STRINGS)('escapeAttr(%p) совпадает с Free-эталоном', (probe) => {
        expect(window.PlathixEscape.escapeAttr(probe)).toBe(freeEscapeAttr(probe));
    });
});
