import { escapeHtml, escapeAttr } from '../escape.js';

describe('escapeHtml', () => {
    it('escapes untrusted output for the destination context', () => expect(escapeHtml('&')).toBe('&amp;'));
    it('escapes untrusted output for the destination context', () => expect(escapeHtml('<script>')).toBe('&lt;script&gt;'));
    it('escapes untrusted output for the destination context', () => expect(escapeHtml('"hello"')).toBe('&quot;hello&quot;'));
    it('escapes untrusted output for the destination context', () => expect(escapeHtml("it's")).toBe("it&#39;s"));
    it('escapes untrusted output for the destination context', () => expect(escapeHtml('safe')).toBe('safe'));
    it('escapes untrusted output for the destination context', () => expect(escapeHtml('')).toBe(''));
});


describe('escapeAttr', () => {
    it('escapes untrusted output for the destination context', () => expect(escapeAttr('&')).toBe('&amp;'));
    it('escapes untrusted output for the destination context', () => expect(escapeAttr('<script>')).toBe('&lt;script&gt;'));
    it('escapes untrusted output for the destination context', () => expect(escapeAttr('"hello"')).toBe('&quot;hello&quot;'));
    it('escapes untrusted output for the destination context', () => expect(escapeAttr("it's")).toBe("it&#39;s"));
    it('escapes untrusted output for the destination context', () => expect(escapeAttr('safe')).toBe('safe'));
    it('escapes untrusted output for the destination context', () => expect(escapeAttr('')).toBe(''));
});
