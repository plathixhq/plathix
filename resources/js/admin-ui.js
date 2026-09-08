import '../css/admin-ui.css';
import { initInlineTabs } from './admin-ui/inline-tabs.js';
import { initRailToggle } from './admin-ui/rail-toggle.js';
import { initShortcodesFilter } from './admin-ui/shortcodes-filter.js';
import { initShortcodesCopy } from './admin-ui/shortcodes-copy.js';






document.addEventListener('DOMContentLoaded', () => {
    initRailToggle();
    initInlineTabs();
    initShortcodesFilter();
    initShortcodesCopy();
});
