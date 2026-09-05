jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

import { footerTemplate } from '../footer.js';

/**
 * Поведенческий proof для [internal]: домен сайта клиента не должен уезжать на plathix.com.
 *
 * Проверка идёт по СОБРАННОЙ разметке, а не по исходнику модуля: source-scan (`rg` по файлу)
 * доказывает лишь отсутствие строки, но не то, что ссылка осталась рабочей. URL содержит
 * четыре амперсанда и вставляется через innerHTML — класс ошибки, который сканом не ловится.
 */
describe('sidebar footer marketing link', () => {
    const render = () => {
        const host = document.createElement('div');
        host.innerHTML = footerTemplate();

        return host;
    };

    beforeEach(() => {
        delete window.Plathix;
    });

    it('renders exactly one marketing link inside the footer', () => {
        const links = render().querySelectorAll('.plathix-sidebar__footer a');

        expect(links).toHaveLength(1);
    });

    it('carries the static utm tags shared with the PHP contour', () => {
        const link = render().querySelector('.plathix-sidebar__footer a');

        // href непустой и разобрался как URL — ловит битые кавычки и амперсанды.
        const url = new URL(link.getAttribute('href'));

        expect(url.origin + url.pathname).toBe('https://plathix.com/');
        expect(url.searchParams.get('utm_source')).toBe('plathix-plugin');
        expect(url.searchParams.get('utm_medium')).toBe('plathix-admin');
        expect(url.searchParams.get('utm_campaign')).toBe('plathix-plugin');
        expect(url.searchParams.get('utm_content')).toBe('sidebar_footer');
    });

    it('never leaks the site hostname into the rendered markup', () => {
        const markup = render().innerHTML;

        expect(markup).not.toContain(window.location.hostname);
        // Именно параметр ref, а не подстрока href= — иначе assert ложно срабатывает
        // на любом атрибуте ссылки.
        expect(markup).not.toMatch(/[?&](amp;)?ref=/);
    });

    it('still lets PRO override the footer content entirely', () => {
        window.Plathix = { footerContent: '<span class="pro-footer">PRO</span>' };

        const host = render();

        expect(host.querySelector('.pro-footer')).not.toBeNull();
        expect(host.querySelectorAll('.plathix-sidebar__footer a')).toHaveLength(0);
    });
});
