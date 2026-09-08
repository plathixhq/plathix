

import { getRuntime } from '../runtime.js';
import { Events } from '../events.js';
import { CONTAINER_ID, fetchAndRenderTiles } from './trash-core.js';

const FORM_SEL = 'form#posts-filter';
const TABLENAV_TOP_SEL = '.tablenav.top';


function isTrashOpen(store) {
    const trashId = Number(getRuntime().trashFolderId || 0);
    return trashId > 0 && Number(store?.openId || 0) === trashId;
}


function formEl() {
    return document.querySelector(FORM_SEL);
}



function positionContainer(container) {
    const form = formEl();
    if (!form) {
        return false;
    }
    const anchor = form.querySelector(TABLENAV_TOP_SEL) || form.querySelector('.wp-list-table');
    if (container.parentElement === form && container.nextElementSibling === anchor) {
        return true;
    }

    form.insertBefore(container, anchor);
    return true;
}


function removePanel() {
    document.getElementById(CONTAINER_ID)?.remove();
}



async function refresh(store) {
    if (!isTrashOpen(store)) {
        removePanel();
        return;
    }
    let container = document.getElementById(CONTAINER_ID);
    if (!container) {
        container = document.createElement('div');
        container.id = CONTAINER_ID;
        container.className = 'plathix-folder-trash-panel';
    }
    if (!positionContainer(container)) {
        return;
    }
    await fetchAndRenderTiles(container, store);
}



function scheduleInitialMount(store) {
    let retries = 0;
    const tryMount = () => {
        if (isTrashOpen(store)) {
            refresh(store);
            const container = document.getElementById(CONTAINER_ID);
            const form = formEl();
            const tablenavTop = form?.querySelector(TABLENAV_TOP_SEL);
            const mounted = !!container && container.parentElement === form && container.nextElementSibling === tablenavTop;
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



export function initFolderTrashPanelList(store) {
    if (!store) {
        return;
    }




    window.wp?.hooks?.addAction?.('plathix.navigationComplete', 'plathix/folder-trash-panel-list', () => refresh(store));


    window.addEventListener(Events.FOLDER_DELETED, () => refresh(store));


    scheduleInitialMount(store);
}
