/**
 * [internal]: drag файла ИЗ Корзины/trashed-папки на обычную папку должен показывать
 * confirm ПЕРЕД вызовом moveItemsBulk — иначе (без confirm) файл только переназначает
 * term без восстановления, а UI лжёт про success (тот же класс бага, что #172).
 */
import Alpine from 'alpinejs';
import { enableAttachmentDrop } from '../dnd.js';
import { getStateValue } from '../state.js';

jest.mock('../i18n.js', () => ({ t: (_key, fallback) => fallback }));

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

describe('dnd.js — drag из корзины требует confirm перед move ([internal])', () => {
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

    it('показывает confirm, если текущая папка — корневая Корзина (openId === trashFolderId)', () => {
        const store = makeStore({ openId: 158 });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(true);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });

    it('показывает confirm, если текущая папка — вложенная trashed-папка (isCurrentFolderTrashed=true)', () => {
        const store = makeStore({ openId: 42, isCurrentFolderTrashed: jest.fn(() => true) });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(true);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });

    it('НЕ показывает confirm для обычного (не-trash) источника', () => {
        const store = makeStore({ openId: 3 });
        Alpine.store('plathix', store);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(store.moveItemsBulk).toHaveBeenCalledWith([1], 7);
    });

    it('отказ от confirm НЕ вызывает moveItemsBulk (файл остаётся нетронутым)', () => {
        const store = makeStore({ openId: 158 });
        Alpine.store('plathix', store);
        confirmSpy.mockReturnValue(false);
        const folderEl = makeFolderEl(7);

        dispatchDrop(folderEl, [1]);

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(store.moveItemsBulk).not.toHaveBeenCalled();
    });
});
