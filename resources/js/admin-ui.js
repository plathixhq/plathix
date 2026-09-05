import '../css/admin-ui.css';
import { initInlineTabs } from './admin-ui/inline-tabs.js';
import { initRailToggle } from './admin-ui/rail-toggle.js';
import { initShortcodesFilter } from './admin-ui/shortcodes-filter.js';
import { initShortcodesCopy } from './admin-ui/shortcodes-copy.js';

// Генератор шорткодов больше НЕ живёт в admin-ui. Он автономен: грузится своим
// бандлом shortcode-builder.js (plathixPro/resources/js/shortcode-builder/standalone.js), который
// сам поднимает store/компонент/overlay/CSS. admin-ui к генератору отношения не имеет —
// см. [internal] (откат paразитного boot из [internal]).

document.addEventListener('DOMContentLoaded', () => {
    initRailToggle();
    initInlineTabs();
    initShortcodesFilter();
    initShortcodesCopy();
});
