import { StaticListNavigationManager } from './manager.js';
import { bindFolderMutationSlot } from './cache.js';
import { hasStateFlag, setStateFlag } from '../state.js';
import { initPrefetch, destroyPrefetch } from './prefetch.js';

let _manager = null;
let _prefetchContainer = null;

export function getStaticListManager() {
    return _manager;
}

export function initStaticListNavigation() {
    if (hasStateFlag('staticListNavInit')) return;

    // [internal]: слот внешних мутаций папок. Подписка живёт здесь, а не в bootstrap
    // сайдбара, потому что кэш фрагментов существует только вместе со static-list —
    // слушать инвалидацию там, где нет кэша, нечего.
    bindFolderMutationSlot();

    _manager = new StaticListNavigationManager();
    _manager.init();

    _prefetchContainer = document.getElementById('plathix-sidebar-root') ?? document.body;
    initPrefetch(_prefetchContainer);

    setStateFlag('staticListNavInit');
}

export function destroyStaticListNavigation() {
    if (_prefetchContainer) {
        destroyPrefetch(_prefetchContainer);
        _prefetchContainer = null;
    }
    _manager?.destroy();
    _manager = null;
}

export function shouldUseStaticListNavigation() {
    return _manager !== null;
}
