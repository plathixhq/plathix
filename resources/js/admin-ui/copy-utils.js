export function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    // [internal]: было .style.position/.left — общий offscreen-helper класс
    // (resources/css/admin-ui.css), тот же паттерн что shortcodes-copy.js.
    textarea.className = 'plathix-visually-offscreen';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
}

export function showCopiedNotice(notice) {
    if (!notice) {
        return;
    }

    notice.classList.add('is-visible');
    window.setTimeout(() => {
        notice.classList.remove('is-visible');
    }, 1800);
}
