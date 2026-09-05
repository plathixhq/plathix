import { __ } from '@wordpress/i18n';

// [internal]: сворачивание сайдбара в rail. Состояние (свёрнут/развёрнут) хранится в
// localStorage per-browser (user-preference, не стоит серверного round-trip). Класс .is-rail
// живёт на .plathix-layout — его читают и сайдбар, и кнопка-тумблер, и подписи. Дефолт — развёрнуто
// (как было до фичи), поэтому при первом визите мигания нет. localStorage обёрнут в try/catch:
// в приватном режиме фича деградирует до «не запоминается между загрузками», но работает в сессии.
const RAIL_STORAGE_KEY = 'plathix_admin_rail';

function readRailCollapsed() {
    try {
        return window.localStorage.getItem(RAIL_STORAGE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function writeRailCollapsed(collapsed) {
    try {
        window.localStorage.setItem(RAIL_STORAGE_KEY, collapsed ? '1' : '0');
    } catch (e) {
        /* localStorage недоступен — состояние не запоминается, это допустимо */
    }
}

export function initRailToggle() {
    const layout = document.querySelector('.plathix-layout');
    if (!layout) return;

    const toggle = layout.querySelector('.plathix-rail__toggle');
    const navItems = layout.querySelectorAll('.plathix-nav__item[data-plathix-label]');

    // Подпись пункта в rail — единый плавающий tooltip. position:fixed (в <body>) вырывается из
    // overflow/clip сайдбара и layout, поэтому не режется и рисуется поверх контента. Нативный
    // title не годится (браузер рисует у КУРСОРА, не центрируется по иконке). Позиционируется
    // строго по геометрии ИКОНКИ: вертикальный центр иконки + справа от пункта.
    const tip = document.createElement('div');
    tip.className = 'plathix-rail__tip-float';
    document.body.appendChild(tip);

    const showTip = (item) => {
        if (!layout.classList.contains('is-rail')) return;
        const icon = item.querySelector('.plathix-nav__icon') || item;
        const iconRect = icon.getBoundingClientRect();
        const itemRect = item.getBoundingClientRect();
        tip.textContent = item.dataset.plathixLabel || '';
        tip.style.top = (iconRect.top + iconRect.height / 2) + 'px'; // вертикальный центр иконки
        tip.style.left = (itemRect.right + 12) + 'px';               // справа от пункта
        tip.classList.add('is-visible');
    };
    const hideTip = () => tip.classList.remove('is-visible');

    navItems.forEach((item) => {
        item.addEventListener('mouseenter', () => showTip(item));
        item.addEventListener('mouseleave', hideTip);
    });

    const apply = (collapsed) => {
        layout.classList.toggle('is-rail', collapsed);
        if (!collapsed) hideTip();
        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            const label = collapsed
                ? __('Expand navigation', 'plathix')
                : __('Collapse navigation', 'plathix');
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
        }
    };

    // Восстановить сохранённое состояние сразу (скрипт в футере — .plathix-layout уже в DOM).
    // БЕЗ анимации: transition висит под .plathix-rail-animate, который добавляем ПОСЛЕ первичного
    // apply (в следующем кадре). Иначе сохранённый rail стартовал 240px и анимированно
    // сворачивался — «раскрывается и сворачивается» при переходе ([internal]).
    apply(readRailCollapsed());
    requestAnimationFrame(() => layout.classList.add('plathix-rail-animate'));

    toggle?.addEventListener('click', () => {
        const collapsed = !layout.classList.contains('is-rail');
        apply(collapsed);
        writeRailCollapsed(collapsed);
    });
}
