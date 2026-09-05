import { __ } from '@wordpress/i18n';
import { fallbackCopy } from '../../../../resources/js/admin-ui/copy-utils.js';
// CSS страницы Settings co-located ([internal]): MiniCss извлекает
// assets/css/admin-ui/settings.css, который SettingsPage::enqueue_scripts грузит
// только на странице Settings (вынос из общего admin-ui.css).
import './settings.css';

function initSvgConditional() {
    // [internal]: SVG-настройка стала трёхзначной политикой (select #plathix-svg-policy).
    // Зависимая секция (роли/safe mode) релевантна только при политике 'sanitize' — при
    // block/ignore SVG не очищается Plathix, роли/safe mode бессмысленны.
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
    // [internal]: видимость решает сервер (SettingsView::render_tab_form() читает
    // $_GET['settings-updated'] напрямую) — location.search ненадёжен, WP core admin
    // синхронизирует адресную строку с <link rel="canonical"> до DOMContentLoaded, и
    // settings-updated в нём уже нет. JS отвечает только за автоскрытие уже видимого бейджа.
    //
    // render_tab_form() рендерит один <form> на каждый таб (все в DOM одновременно,
    // неактивные скрыты через [hidden] на <section>). id теперь уникален per-tab
    // (plathix-saved-notice-{slug}), но все табы всё равно в DOM одновременно —
    // scoping на активную (не-hidden) панель остаётся обязательным, иначе
    // querySelector с префиксным атрибутом взял бы первый попавшийся, не обязательно активный.
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
