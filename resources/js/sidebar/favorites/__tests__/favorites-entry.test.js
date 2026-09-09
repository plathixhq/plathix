

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

describe('escapes untrusted output for the destination context', () => {
    it('escapes untrusted output for the destination context', () => {
        const html = favCtxItemHTML('Add to favorites', 'Remove from favorites');
        const xText = extractXText(html);

        expect(evaluateXText(xText, false)).toBe('Add to favorites');
        expect(evaluateXText(xText, true)).toBe('Remove from favorites');
    });

    it('escapes untrusted output for the destination context', () => {
        const addLabel = "Aggiungi ai preferiti (cartella dell'utente)";
        const removeLabel = "Rimuovi dai preferiti (cartella dell'utente)";
        const html = favCtxItemHTML(addLabel, removeLabel);
        const xText = extractXText(html);



        expect(() => evaluateXText(xText, false)).not.toThrow();

        expect(evaluateXText(xText, false)).toBe(addLabel);
        expect(evaluateXText(xText, true)).toBe(removeLabel);
    });

    it('escapes untrusted output for the destination context', () => {
        const addLabel = 'Add "favorite" <folder>';
        const removeLabel = 'Remove "favorite" <folder>';
        const html = favCtxItemHTML(addLabel, removeLabel);

        const container = document.createElement('div');
        container.innerHTML = html;

        expect(container.querySelectorAll('span').length).toBe(1);
        expect(container.querySelectorAll('button').length).toBe(1);

        const xText = extractXText(html);
        expect(evaluateXText(xText, false)).toBe(addLabel);
        expect(evaluateXText(xText, true)).toBe(removeLabel);
    });
});
