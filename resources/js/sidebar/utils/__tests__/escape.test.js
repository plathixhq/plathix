import { escapeHtml, escapeAttr } from '../escape.js';

describe('escapeHtml', () => {
    it('covers public behavior without internal references', () => expect(escapeHtml('&')).toBe('&amp;'));
    it('covers public behavior without internal references', () => expect(escapeHtml('<script>')).toBe('&lt;script&gt;'));
    it('covers public behavior without internal references', () => expect(escapeHtml('"hello"')).toBe('&quot;hello&quot;'));
    it('covers public behavior without internal references', () => expect(escapeHtml("it's")).toBe("it&#39;s"));
    it('covers public behavior without internal references', () => expect(escapeHtml('safe')).toBe('safe'));
    it('covers public behavior without internal references', () => expect(escapeHtml('')).toBe(''));
});


describe('escapeAttr', () => {
    it('covers public behavior without internal references', () => expect(escapeAttr('&')).toBe('&amp;'));
    it('covers public behavior without internal references', () => expect(escapeAttr('<script>')).toBe('&lt;script&gt;'));
    it('covers public behavior without internal references', () => expect(escapeAttr('"hello"')).toBe('&quot;hello&quot;'));
    it('covers public behavior without internal references', () => expect(escapeAttr("it's")).toBe("it&#39;s"));
    it('covers public behavior without internal references', () => expect(escapeAttr('safe')).toBe('safe'));
    it('covers public behavior without internal references', () => expect(escapeAttr('')).toBe(''));
});
