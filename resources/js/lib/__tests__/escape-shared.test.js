import { escapeHtml as freeEscapeHtml, escapeAttr as freeEscapeAttr } from '../../sidebar/utils/escape.js';








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

describe('escapes untrusted output for the destination context', () => {
    beforeAll(() => {
        delete window.PlathixEscape;
        require('../escape-shared.js');
    });

    it('escapes untrusted output for the destination context', () => {
        expect(typeof window.PlathixEscape.escapeHtml).toBe('function');
        expect(typeof window.PlathixEscape.escapeAttr).toBe('function');
    });

    it.each(PROBE_STRINGS)('escapes untrusted output for the destination context', (probe) => {
        expect(window.PlathixEscape.escapeHtml(probe)).toBe(freeEscapeHtml(probe));
    });

    it.each(PROBE_STRINGS)('escapes untrusted output for the destination context', (probe) => {
        expect(window.PlathixEscape.escapeAttr(probe)).toBe(freeEscapeAttr(probe));
    });
});
