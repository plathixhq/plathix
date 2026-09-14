

import { colorItemHTML } from '../color-entry.js';

describe('escapes untrusted output for the destination context', () => {
    it('renders an ordinary label unchanged (baseline)', () => {
        const html = colorItemHTML('Color');

        const container = document.createElement('div');
        container.innerHTML = html;
        expect(container.textContent).toContain('Color');
    });

    it('escapes HTML special characters in the label before inserting into innerHTML', () => {
        const html = colorItemHTML('<img src=x onerror=alert(1)>');

        expect(html).not.toContain('<img src=x onerror=alert(1)>');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');

        const container = document.createElement('div');
        container.innerHTML = html;
        expect(container.querySelector('img')).toBeNull();
    });
});
