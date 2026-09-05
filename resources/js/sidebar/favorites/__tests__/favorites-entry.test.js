/**
 * [internal]: i18n-метка (add/remove favorite) вставлялась в favCtxItemHTML() без
 * экранирования — тройной вложенный контекст (HTML-атрибут -> Alpine x-text выражение ->
 * JS-строковый литерал). Апостроф в переводе (легитимно в it/fr — "L'aggiungi",
 * "d'ajouter") закрывал JS-литерал раньше времени и ломал разметку/выражение.
 *
 * Проверяем по образцу overlays.test.js ([internal], идентичный класс бага): реальный
 * HTML-парсинг сгенерированной разметки + eval x-text выражения как настоящего JS.
 */
import { favCtxItemHTML } from '../favorites-entry.js';

function extractXText(html) {
    const container = document.createElement('div');
    container.innerHTML = html;
    const span = container.querySelector('span');
    return span.getAttribute('x-text');
}

function evaluateXText(xText, isFavorite) {
    const store = { plathix: { isFavorite: () => isFavorite, contextMenuFolderId: 5 } };
    // eslint-disable-next-line no-new-func
    const evaluate = new Function('$store', 'return ' + xText);
    return evaluate(store);
}

describe('favorites-entry.js — favCtxItemHTML escapes i18n labels ([internal])', () => {
    it('переводы без спецсимволов рендерятся как есть (baseline)', () => {
        const html = favCtxItemHTML('Add to favorites', 'Remove from favorites');
        const xText = extractXText(html);

        expect(evaluateXText(xText, false)).toBe('Add to favorites');
        expect(evaluateXText(xText, true)).toBe('Remove from favorites');
    });

    it('апостроф в переводе не ломает JS-литерал и не исполняется как код ([internal])', () => {
        const addLabel = "Aggiungi ai preferiti (cartella dell'utente)";
        const removeLabel = "Rimuovi dai preferiti (cartella dell'utente)";
        const html = favCtxItemHTML(addLabel, removeLabel);
        const xText = extractXText(html);

        // Сам факт, что new Function не бросает SyntaxError, доказывает что апостроф
        // не разорвал JS-строковый литерал — до фикса это выражение было невалидным JS.
        expect(() => evaluateXText(xText, false)).not.toThrow();

        expect(evaluateXText(xText, false)).toBe(addLabel);
        expect(evaluateXText(xText, true)).toBe(removeLabel);
    });

    it('двойная кавычка и HTML-спецсимволы в переводе не выходят из HTML-атрибута', () => {
        const addLabel = 'Add "favorite" <folder>';
        const removeLabel = 'Remove "favorite" <folder>';
        const html = favCtxItemHTML(addLabel, removeLabel);

        const container = document.createElement('div');
        container.innerHTML = html;
        // Один <span> внутри <button> — разметка не разорвалась лишними узлами/атрибутами.
        expect(container.querySelectorAll('span').length).toBe(1);
        expect(container.querySelectorAll('button').length).toBe(1);

        const xText = extractXText(html);
        expect(evaluateXText(xText, false)).toBe(addLabel);
        expect(evaluateXText(xText, true)).toBe(removeLabel);
    });
});
