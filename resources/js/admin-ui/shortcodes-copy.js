/**
 * Копирование шорткода по клику на поле (.plathix-shortcode-copy) на странице Шорткоды.
 *
 * Перенесено 1:1 из инлайн-<script> в ShortcodesListPage.php ([internal]), чтобы
 * убрать дублирование/инлайн-JS. Поведение не менялось: фокус выделяет текст, клик
 * копирует value (или data-shortcode) и кратко показывает плашку рядом.
 *
 * @param {Document} [doc=document]
 */
export function initShortcodesCopy(doc = document) {
    doc.querySelectorAll('.plathix-shortcode-copy').forEach((el) => {
        el.addEventListener('focus', () => el.select());
        el.addEventListener('click', () => {
            el.select();
            const text = el.value || el.dataset.shortcode;
            const notice = el.nextElementSibling;
            const show = () => {
                if (!notice) {
                    return;
                }
                // [internal]: opacity fade оставлен как прямой .style.opacity —
                // без CSS transition сейчас (моментальный переход), вынос в
                // classList.toggle добавил бы transition неявно только если объявить
                // его в классе, что изменило бы визуальное поведение. Не в scope.
                notice.style.opacity = '1';
                setTimeout(() => { notice.style.opacity = '0'; }, 1800);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(show).catch(() => {});
            } else {
                const ta = doc.createElement('textarea');
                ta.value = text;
                // [internal]: было .style.cssText — общий offscreen-helper класс
                // (resources/css/admin-ui.css), тот же паттерн что copy-utils.js.
                ta.className = 'plathix-visually-offscreen';
                doc.body.appendChild(ta);
                ta.select();
                try {
                    doc.execCommand('copy');
                    show();
                } catch (e) { /* noop */ }
                doc.body.removeChild(ta);
            }
        });
    });
}
