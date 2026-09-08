import { StaticListNavigationManager } from './manager.js';
import { bindFolderMutationSlot } from './cache.js';
import { hasStateFlag, setStateFlag } from '../state.js';
import { initPrefetch } from './prefetch.js';

let _manager = null;

export function getStaticListManager() {
    return _manager;
}

export function initStaticListNavigation() {
    if (hasStateFlag('staticListNavInit')) return;




    bindFolderMutationSlot();

    _manager = new StaticListNavigationManager();
    _manager.init();

    initPrefetch(document.getElementById('plathix-sidebar-root') ?? document.body);

    setStateFlag('staticListNavInit');
}
