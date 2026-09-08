

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




                notice.style.opacity = '1';
                setTimeout(() => { notice.style.opacity = '0'; }, 1800);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(show).catch(() => {});
            } else {
                const ta = doc.createElement('textarea');
                ta.value = text;


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
