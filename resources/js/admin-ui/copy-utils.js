export function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');


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
