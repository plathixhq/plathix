import { escapeHtml, escapeAttr } from '../escape.js';

describe('escapeHtml', () => {
    it('экранирует &', () => expect(escapeHtml('&')).toBe('&amp;'));
    it('экранирует < и >', () => expect(escapeHtml('<script>')).toBe('&lt;script&gt;'));
    it('экранирует двойные кавычки', () => expect(escapeHtml('"hello"')).toBe('&quot;hello&quot;'));
    it('экранирует одинарные кавычки', () => expect(escapeHtml("it's")).toBe("it&#39;s"));
    it('не трогает безопасную строку', () => expect(escapeHtml('safe')).toBe('safe'));
    it('возвращает пустую строку', () => expect(escapeHtml('')).toBe(''));
});

// [internal]: escapeAttr консолидирован сюда из trash-core.js ([internal] fix).
describe('escapeAttr', () => {
    it('экранирует &', () => expect(escapeAttr('&')).toBe('&amp;'));
    it('экранирует < и >', () => expect(escapeAttr('<script>')).toBe('&lt;script&gt;'));
    it('экранирует двойные кавычки', () => expect(escapeAttr('"hello"')).toBe('&quot;hello&quot;'));
    it('экранирует одинарные кавычки ([internal] — critical для атрибутного контекста)', () => expect(escapeAttr("it's")).toBe("it&#39;s"));
    it('не трогает безопасную строку', () => expect(escapeAttr('safe')).toBe('safe'));
    it('возвращает пустую строку', () => expect(escapeAttr('')).toBe(''));
});
