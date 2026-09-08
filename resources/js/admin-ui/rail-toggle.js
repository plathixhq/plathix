import { __ } from '@wordpress/i18n';






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
        
    }
}

export function initRailToggle() {
    const layout = document.querySelector('.plathix-layout');
    if (!layout) return;

    const toggle = layout.querySelector('.plathix-rail__toggle');
    const navItems = layout.querySelectorAll('.plathix-nav__item[data-plathix-label]');





    const tip = document.createElement('div');
    tip.className = 'plathix-rail__tip-float';
    document.body.appendChild(tip);

    const showTip = (item) => {
        if (!layout.classList.contains('is-rail')) return;
        const icon = item.querySelector('.plathix-nav__icon') || item;
        const iconRect = icon.getBoundingClientRect();
        const itemRect = item.getBoundingClientRect();
        tip.textContent = item.dataset.plathixLabel || '';
        tip.style.top = (iconRect.top + iconRect.height / 2) + 'px';
        tip.style.left = (itemRect.right + 12) + 'px';
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





    apply(readRailCollapsed());
    requestAnimationFrame(() => layout.classList.add('plathix-rail-animate'));

    toggle?.addEventListener('click', () => {
        const collapsed = !layout.classList.contains('is-rail');
        apply(collapsed);
        writeRailCollapsed(collapsed);
    });
}
