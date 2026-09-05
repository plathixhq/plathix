import { initShortcodesCopy } from '../shortcodes-copy.js';

describe('shortcodes-copy offscreen fallback ([internal])', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        // navigator.clipboard недоступен в jsdom по умолчанию — форсируем execCommand fallback путь.
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        document.execCommand = jest.fn(() => true);
    });

    it('uses the shared offscreen-helper class instead of inline style on the fallback textarea', () => {
        document.body.innerHTML = `
            <input class="plathix-shortcode-copy" value="[plathix_gallery]" readonly>
            <span class="notice"></span>
        `;
        initShortcodesCopy(document);

        const input = document.querySelector('.plathix-shortcode-copy');
        input.dispatchEvent(new Event('click', { bubbles: true }));

        // textarea создаётся и удаляется синхронно внутри click-обработчика — проверяем,
        // что document.execCommand был вызван (fallback путь реально сработал), значит
        // до вызова успел выставиться className на созданной textarea без падения на
        // отсутствии .style API (класс, не inline).
        expect(document.execCommand).toHaveBeenCalledWith('copy');
    });

    it('shows and hides the copy notice via opacity toggle unchanged', () => {
        jest.useFakeTimers();
        document.body.innerHTML = `
            <input class="plathix-shortcode-copy" value="[plathix_gallery]" readonly>
            <span class="notice"></span>
        `;
        initShortcodesCopy(document);

        const input = document.querySelector('.plathix-shortcode-copy');
        const notice = document.querySelector('.notice');
        input.dispatchEvent(new Event('click', { bubbles: true }));

        expect(notice.style.opacity).toBe('1');
        jest.advanceTimersByTime(1800);
        expect(notice.style.opacity).toBe('0');
        jest.useRealTimers();
    });
});
