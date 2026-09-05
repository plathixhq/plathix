import { bindFolderSwitchUi } from './folder-switch-ui.js';

function bootFolderSwitchUi() {
    bindFolderSwitchUi();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootFolderSwitchUi);
} else {
    bootFolderSwitchUi();
}
