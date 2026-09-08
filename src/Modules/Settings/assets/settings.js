import { __ } from '@wordpress/i18n';
import { fallbackCopy } from '../../../../resources/js/admin-ui/copy-utils.js';



import './settings.css';

function initSvgConditional() {



    const svgPolicy = document.getElementById('plathix-svg-policy');
    const svgFields = document.getElementById('plathix-svg-dependent');
    if (!svgPolicy || !svgFields) return;

    const sync = () => {
        svgFields.style.display = svgPolicy.value === 'sanitize' ? '' : 'none';
    };
    sync();
    svgPolicy.addEventListener('change', sync);
}

function initSavedNotice() {




    //





    const notice = document.querySelector('[data-plathix-tab-panel]:not([hidden]) [id^="plathix-saved-notice-"]');
    if (!notice || notice.style.display === 'none') return;
    setTimeout(() => { notice.style.display = 'none'; }, 2400);
}

function initCopyButton(buttonId, noticeId) {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    btn.addEventListener('click', function() {
        const key = this.dataset.key || '';
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(key).catch(() => fallbackCopy(key));
        } else {
            fallbackCopy(key);
        }
        const notice = document.getElementById(noticeId);
        if (notice) {
            notice.style.display = 'inline-flex';
            setTimeout(() => { notice.style.display = 'none'; }, 1800);
        }
    });
}

function initApiKeyReveal() {
    const btn     = document.getElementById('plathix-reveal-key');
    const display = document.getElementById('plathix-key-display');
    if (!btn || !display) return;
    btn.addEventListener('click', function() {
        const showing = display.dataset.showing === '1';
        display.textContent      = showing ? display.dataset.masked : display.dataset.full;
        display.dataset.showing  = showing ? '0' : '1';
        this.textContent         = showing ? __('Reveal', 'plathix') : __('Hide', 'plathix');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initSvgConditional();
    initSavedNotice();
    initCopyButton('plathix-copy-service-token', 'plathix-copy-service-token-notice');
    initApiKeyReveal();
});
