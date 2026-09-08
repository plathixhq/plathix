

import { getRuntime } from '../runtime.js';
import { Events } from '../events.js';

import { CONTAINER_ID, fetchAndRenderTiles } from './trash-core.js';
import { setStateValue } from '../state.js';

const BROWSER_SEL = '.attachments-browser';
const WRAPPER_SEL = '.attachments-wrapper';

let mountObserver = null;


function isTrashOpen(store) {
    const trashId = Number(getRuntime().trashFolderId || 0);
    return trashId > 0 && Number(store?.openId || 0) === trashId;
}

export function initFolderTrashPanel(store) {
    if (!store) {
        return;
    }


    const refresh = () => {
        if (isTrashOpen(store)) {
            renderPanel(store);
        } else {
            removePanel();
        }
    };

    window.wp?.hooks?.addAction?.('plathix.folderOpened', 'plathix/folder-trash-panel', refresh);

    window.addEventListener(Events.FOLDER_DELETED, refresh);



    ensureObserver();






    scheduleInitialMount(store, refresh);
}



function scheduleInitialMount(store, refresh) {
    let retries = 0;
    const tryMount = () => {




        if (isTrashOpen(store)) {
            refresh();


            const container = document.getElementById(CONTAINER_ID);
            const wrapper = browserEl()?.querySelector(`:scope > ${WRAPPER_SEL}`) || document.querySelector(WRAPPER_SEL);
            const mounted = !!container && container.parentElement === browserEl() && container.nextElementSibling === wrapper;
            if (mounted) {
                return;
            }
        }
        if (++retries < 20) {
            setTimeout(tryMount, 150);
        }
    };
    tryMount();
}


function browserEl() {
    return document.querySelector(BROWSER_SEL);
}


function positionContainer(container) {
    const browser = browserEl();
    const wrapper = browser?.querySelector(`:scope > ${WRAPPER_SEL}`) || document.querySelector(WRAPPER_SEL);
    if (!browser || !wrapper) {
        return false;
    }

    if (container.parentElement === browser && container.nextElementSibling === wrapper) {
        return true;
    }
    browser.insertBefore(container, wrapper);
    return true;
}

function ensureObserver() {
    if (mountObserver) {
        return;
    }
    mountObserver = new MutationObserver(() => {
        const container = document.getElementById(CONTAINER_ID);

        if (container) {
            positionContainer(container);
        }
    });
    const browser = browserEl();
    if (browser) {
        mountObserver.observe(browser, { childList: true });


        setStateValue('trashPanelMountObserver', mountObserver);
    }
}

function removePanel() {
    document.getElementById(CONTAINER_ID)?.remove();
}



async function renderPanel(store) {
    let container = document.getElementById(CONTAINER_ID);
    if (!container) {
        container = document.createElement('div');
        container.id = CONTAINER_ID;
        container.className = 'plathix-folder-trash-panel';
    }
    if (!positionContainer(container)) {
        return;
    }
    ensureObserver();

    await fetchAndRenderTiles(container, store);
}
