

import Alpine from 'alpinejs';
import { enableAttachmentDrop } from '../dnd.js';
import { getStateValue } from '../state.js';
import { t } from '../i18n.js';

jest.mock('../i18n.js', () => ({ t: jest.fn((_key, fallback) => fallback) }));

function makeStore(extraState = {}) {
    return {
        canAssign: true,
        openId: 0,
        bulkSafeMode: false,
        isCurrentFolderTrashed: jest.fn(() => false),
        getSelectedItemIds: jest.fn(() => [1]),
        moveItemsBulk: jest.fn(),
        ...extraState,
    };
}

function dispatchDrop(folderEl, itemIds) {
    const dataTransfer = {
        _data: {},
        setData(type, value) { this._data[type] = value; },
        getData(type) { return this._data[type] || ''; },
        effectAllowed: null,
    };
    dataTransfer.setData('application/x-plathix-item', JSON.stringify(itemIds));

    const dragstart = new Event('dragstart', { bubbles: true, cancelable: true });
    dragstart.dataTransfer = dataTransfer;
    const attachment = document.createElement('div');
    attachment.className = 'attachment';
    attachment.dataset.id = String(itemIds[0]);
    document.body.appendChild(attachment);
    attachment.dispatchEvent(dragstart);

    const drop = new Event('drop', { bubbles: true, cancelable: true });
    drop.dataTransfer = dataTransfer;
    Object.defineProperty(drop, 'target', { value: folderEl, configurable: true });
    window.dispatchEvent(drop);
}

describe('handles trash workflow consistently', () => {
    let confirmSpy;

    beforeEach(() => {
        window.__PlathixState = {};
        document.body.innerHTML = '';
        window.Plathix = { trashFolderId: 158 };
        confirmSpy = jest.spyOn(window, 'confirm');
        enableAttachmentDrop();
    });

    afterEach(() => {
        confirmSpy.mockRestore();
        delete window.Plathix;
        getStateValue('attachmentDnDObserver')?.disconnect();
    });

    function makeFolderEl(id, name = 'Target') {
        const el = document.createElement('div');
        el.className = 'plathix-folder';
        el.dataset.folderId = String(id);
        const nameEl = document.createElement('span');
        nameEl.className = 'plathix-folder__name';
        nameEl.textContent = name;
        el.appendChild(nameEl);
        document.body.appendChild(el);
        return el;
    }

    it('handles trash workflow consistently', () => {
        const store = makeStore({ openId: 158 });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(true);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });

    it('handles trash workflow consistently', () => {
        const store = makeStore({ openId: 42, isCurrentFolderTrashed: jest.fn(() => true) });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(true);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });

    it('handles trash workflow consistently', () => {
        const store = makeStore({ openId: 3 });
        Alpine.store('plathix', store);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });









    it('keeps upload links scoped to the active folder', () => {
        t.mockImplementation((_key, fallback) => fallback);
        const store = makeStore({ openId: 158 });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(true);
        const folderEl = makeFolderEl(7, 'Projects');

        dispatchDrop(folderEl, [1]);

        expect(t).toHaveBeenCalledWith('dragdrop_restore_confirm_named', 'Restore file and move it to folder "%s"?');
        expect(confirmSpy).toHaveBeenCalledWith('Restore file and move it to folder "Projects"?');
    });

    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
        const store = makeStore({ openId: 158 });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(false);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).not.toHaveBeenCalled();
    });
});
