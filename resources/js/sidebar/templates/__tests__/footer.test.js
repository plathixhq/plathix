jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

import { footerTemplate } from '../footer.js';



describe('sidebar footer marketing link', () => {
    const render = () => {
        const host = document.createElement('div');
        host.innerHTML = footerTemplate();

        return host;
    };

    beforeEach(() => {
        delete window.Plathix;
    });

    it('renders no marketing link inside the footer by default', () => {
        const links = render().querySelectorAll('.plathix-sidebar__footer a');

        expect(links).toHaveLength(0);
    });

    it('renders an empty footer block by default', () => {
        const footer = render().querySelector('.plathix-sidebar__footer');

        expect(footer.innerHTML.trim()).toBe('');
    });

    it('never leaks the site hostname into the rendered markup', () => {
        const markup = render().innerHTML;

        expect(markup).not.toContain(window.location.hostname);
        
        
        expect(markup).not.toMatch(/[?&](amp;)?ref=/);
    });

    it('still lets PRO override the footer content entirely', () => {
        window.Plathix = { footerContent: '<span class="pro-footer">PRO</span>' };

        const host = render();

        expect(host.querySelector('.pro-footer')).not.toBeNull();
        expect(host.querySelectorAll('.plathix-sidebar__footer a')).toHaveLength(0);
    });
});
