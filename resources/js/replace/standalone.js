import { bindReplaceMediaUi } from './replace-media-ui.js';

function bootReplaceUi() {
    bindReplaceMediaUi();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootReplaceUi);
} else {
    bootReplaceUi();
}
