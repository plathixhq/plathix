import { fallbackCopy, showCopiedNotice } from '../../../../resources/js/admin-ui/copy-utils.js';
// CSS кнопки копирования co-located ([internal], #113): webpack извлекает
// в assets/css/admin-ui/system-info.css, SystemInfoPage грузит на своей странице.
import './system-info.css';

function buildSysInfoText() {
    const lines = [];
    document.querySelectorAll('[data-sysinfo-section]').forEach(section => {
        lines.push('== ' + section.dataset.sysinfoSection + ' ==');
        section.querySelectorAll('tr[data-sysinfo-label]').forEach(row => {
            lines.push('  ' + row.dataset.sysinfoLabel + ': ' + row.dataset.sysinfoValue);
        });
        lines.push('');
    });
    return lines.join('\n');
}

function initSystemInfoCopy() {
    const button = document.getElementById('plathix-copy-sysinfo');
    const notice = document.getElementById('plathix-copy-sysinfo-notice');

    if (!button) {
        return;
    }

    button.addEventListener('click', () => {
        const text = buildSysInfoText();

        if (!text) {
            return;
        }

        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text)
                .then(() => showCopiedNotice(notice))
                .catch(() => {
                    fallbackCopy(text);
                    showCopiedNotice(notice);
                });
            return;
        }

        fallbackCopy(text);
        showCopiedNotice(notice);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initSystemInfoCopy();
});
