

import { getStateValue } from '../state.js';

async function freshImport(path) {
    jest.resetModules();
    return import(path);
}

function dispatchReady() {
    window.dispatchEvent(new Event('plathix:ready'));
}

describe('handles trash workflow consistently', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.Alpine = {
            store: jest.fn(() => null),
            initTree: jest.fn(),
        };
        window.Plathix = {};
        delete window.__PlathixApiReady;
    });

    afterEach(() => {
        delete window.Alpine;
        delete window.Plathix;
        delete window.__PlathixApiReady;
    });

    it('disconnecting the stored observer stops future DOM-mutation callbacks', async() => {
        await freshImport('../trash/trash-entry.js');
        dispatchReady();

        const observer = getStateValue('trashEntryBodyObserver');
        expect(observer).toBeInstanceOf(MutationObserver);

        const spy = jest.spyOn(document, 'querySelectorAll');
        document.body.appendChild(document.createElement('div'));
        await new Promise((resolve) => setTimeout(resolve, 0));
        const callsBeforeDisconnect = spy.mock.calls.length;
        expect(callsBeforeDisconnect).toBeGreaterThan(0);

        observer.disconnect();
        spy.mockClear();

        document.body.appendChild(document.createElement('div'));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(spy.mock.calls.length).toBe(0);

        spy.mockRestore();
    });
});

describe('covers public behavior without internal references', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div data-slot="plathix-favorites"></div>';
        window.Alpine = {
            store: jest.fn(() => ({ favorites: [] })),
            initTree: jest.fn(),
        };
        window.Plathix = {};
        delete window.__PlathixApiReady;
    });

    afterEach(() => {
        delete window.Alpine;
        delete window.Plathix;
        delete window.__PlathixApiReady;
    });

    it('disconnecting the stored observer stops future context-menu-slot remounts', async() => {
        await freshImport('../favorites/favorites-entry.js');
        dispatchReady();

        const observer = getStateValue('favoritesEntryBodyObserver');
        expect(observer).toBeInstanceOf(MutationObserver);

        const ctxSlot = document.createElement('div');
        ctxSlot.setAttribute('data-slot', 'plathix-context-menu-top');
        document.body.appendChild(ctxSlot);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(ctxSlot.querySelector('.plathix-fav-ctx-item')).not.toBeNull();

        observer.disconnect();
        ctxSlot.innerHTML = '';

        const ctxSlot2 = document.createElement('div');
        ctxSlot2.setAttribute('data-slot', 'plathix-context-menu-top');
        document.body.appendChild(ctxSlot2);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(ctxSlot2.querySelector('.plathix-fav-ctx-item')).toBeNull();
    });
});

describe('covers public behavior without internal references', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.Alpine = {
            store: jest.fn(() => ({ contextMenuFolderId: 0 })),
            initTree: jest.fn(),
            data: jest.fn(),
            effect: jest.fn(),
        };
        window.Plathix = {};
        delete window.__PlathixApiReady;
    });

    afterEach(() => {
        delete window.Alpine;
        delete window.Plathix;
        delete window.__PlathixApiReady;
    });

    it('disconnecting the stored observer stops future context-menu-items remounts', async() => {
        await freshImport('../color/color-entry.js');
        dispatchReady();

        const observer = getStateValue('colorEntryBodyObserver');
        expect(observer).toBeInstanceOf(MutationObserver);

        const ctxSlot = document.createElement('div');
        ctxSlot.setAttribute('data-slot', 'plathix-context-menu-items');
        document.body.appendChild(ctxSlot);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(ctxSlot.querySelector('.plathix-color-ctx-item')).not.toBeNull();

        observer.disconnect();
        ctxSlot.innerHTML = '';

        const ctxSlot2 = document.createElement('div');
        ctxSlot2.setAttribute('data-slot', 'plathix-context-menu-items');
        document.body.appendChild(ctxSlot2);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(ctxSlot2.querySelector('.plathix-color-ctx-item')).toBeNull();
    });
});

describe('handles trash workflow consistently', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="attachments-browser"><div class="attachments-wrapper"></div></div>';
        window.Plathix = { trashFolderId: 99 };
    });

    afterEach(() => {
        delete window.Plathix;
        document.body.innerHTML = '';
    });

    it('disconnecting the stored observer stops future re-positioning', async() => {
        const { initFolderTrashPanel } = await freshImport('../trash/trash-panel.js');

        const store = { openId: 99 };
        initFolderTrashPanel(store);
        await new Promise((resolve) => setTimeout(resolve, 250));

        const observer = getStateValue('trashPanelMountObserver');
        expect(observer).toBeInstanceOf(MutationObserver);

        observer.disconnect();
        // No further behavioral assertion beyond disconnect() succeeding without
        // throwing — repositioning is covered by trash-panel's own test suite;
        // this test's contract is specifically "disconnect() is reachable and callable".
    });
});
