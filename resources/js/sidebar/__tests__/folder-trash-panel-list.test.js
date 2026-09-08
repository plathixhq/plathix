

import { initFolderTrashPanelList } from '../trash/trash-panel-list.js';
import { CONTAINER_ID } from '../trash/trash-core.js';

jest.mock('../api.js', () => ({
    Api: {
        getTrashedFolders: jest.fn(() => Promise.resolve({ folders: [
            { id: 10, name: 'Alpha', kids: 2, deletedAt: 0 },
            { id: 11, name: 'Beta', kids: 0, deletedAt: 0 },
        ] })),
        restoreFolder: jest.fn(() => Promise.resolve({})),
        purgeFolder: jest.fn(() => Promise.resolve({})),
    },
}));

jest.mock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));

jest.mock('../runtime.js', () => ({
    getRuntime: () => ({ trashFolderId: 77 }),
}));

jest.mock('../events.js', () => ({ Events: { FOLDER_DELETED: 'plathix:folder-deleted' } }));


function buildListDom() {
    document.body.innerHTML = `
        <div class="wrap">
            <form id="posts-filter">
                <div class="tablenav top"></div>
                <table class="wp-list-table"><tbody id="the-list"></tbody></table>
            </form>
        </div>`;
}


function installHooks() {
    const actions = {};
    window.wp = {
        hooks: {
            addAction: (name, _ns, cb) => { (actions[name] ||= []).push(cb); },
            doAction: (name, payload) => { (actions[name] || []).forEach((cb) => cb(payload)); },
        },
    };
    return actions;
}

function makeStore(openId) {
    return { openId, refreshFolders: jest.fn(() => Promise.resolve()) };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

beforeEach(() => {
    buildListDom();
    installHooks();
    jest.clearAllMocks();
});

describe('folder-trash-panel-list', () => {
    it('handles trash workflow consistently', async () => {
        initFolderTrashPanelList(makeStore(77)); // openId === trashId
        await flush();

        const container = document.getElementById(CONTAINER_ID);
        expect(container).not.toBeNull();
        const form = document.querySelector('form#posts-filter');
        expect(container.parentElement).toBe(form);

        expect(container.nextElementSibling.classList.contains('tablenav')).toBe(true);
    });

    it('preserves folder tree behavior', async () => {
        initFolderTrashPanelList(makeStore(77));
        await flush();
        await flush();

        const tiles = document.querySelectorAll(`#${CONTAINER_ID} .plathix-folder-trash-panel__tile`);
        expect(tiles.length).toBe(2);
        expect(document.querySelector(`#${CONTAINER_ID}`).textContent).toContain('Alpha');
    });

    it('handles trash workflow consistently', async () => {
        initFolderTrashPanelList(makeStore(5));
        await flush();
        expect(document.getElementById(CONTAINER_ID)).toBeNull();
    });

    it('handles trash workflow consistently', async () => {
        const store = makeStore(77);
        const actions = installHooks();
        initFolderTrashPanelList(store);
        await flush();
        expect(document.getElementById(CONTAINER_ID)).not.toBeNull();


        store.openId = 5;
        actions['plathix.navigationComplete'].forEach((cb) => cb({ folderId: 5 }));
        await flush();
        expect(document.getElementById(CONTAINER_ID)).toBeNull();
    });

    it('covers public behavior without internal references', async () => {
        const store = makeStore(77);
        const actions = installHooks();
        initFolderTrashPanelList(store);
        await flush();


        const form = document.querySelector('form#posts-filter');
        const oldNav = form.querySelector('.tablenav.top');
        const newNav = document.createElement('div');
        newNav.className = 'tablenav top';
        form.replaceChild(newNav, oldNav);


        actions['plathix.navigationComplete'].forEach((cb) => cb({ folderId: 77 }));
        await flush();

        const container = document.getElementById(CONTAINER_ID);
        expect(container).not.toBeNull();
        expect(container.nextElementSibling).toBe(newNav);
    });

    it('covers public behavior without internal references', async () => {

        const form = document.querySelector('form#posts-filter');
        form.querySelector('.tablenav.top').remove();
        initFolderTrashPanelList(makeStore(77));
        await flush();

        const container = document.getElementById(CONTAINER_ID);
        expect(container).not.toBeNull();
        expect(container.closest('form#posts-filter')).toBe(form);

        expect(container.nextElementSibling.classList.contains('wp-list-table')).toBe(true);
    });

    it('covers public behavior without internal references', async () => {
        const store = makeStore(77);
        const actions = installHooks();
        initFolderTrashPanelList(store);
        await flush();
        actions['plathix.navigationComplete'].forEach((cb) => cb({ folderId: 77 }));
        await flush();

        expect(document.querySelectorAll(`#${CONTAINER_ID}`).length).toBe(1);
        expect(document.querySelectorAll('.plathix-folder-trash-panel').length).toBe(1);
    });
});
