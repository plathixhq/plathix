jest.mock('../../events.js', () => ({
    Events: {
        CONTEXT_MENU: 'context-menu',
        DRAG_FOLDER_MIME: 'application/x-folder',
        DRAG_ITEMS_MIME: 'application/x-items',
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

import Alpine from 'alpinejs';
import { folderTree } from '../FolderTree.js';

function makeComponent(storeOverrides = {}) {
    const component = folderTree();
    component.$store = {
        plathix: {
            _childrenByParent: new Map(),
            _hasChildrenSet: new Set(),
            folderSelectMode: false,
            folderDragMode: false,
            newFolderParentId: null,
            canManage: true,
            openFolder: jest.fn(),
            toggleFolderSelected: jest.fn(),
            isFolderSelected: jest.fn(() => false),
            ...storeOverrides,
        },
    };
    component.parentId = 0;
    component.$dispatch = jest.fn();
    return component;
}

describe('FolderTree component', () => {
    it('reads children from the indexed parent map', () => {
        const childA = { id: 2, parentId: 1 };
        const childB = { id: 3, parentId: 1 };
        const component = makeComponent({
            _childrenByParent: new Map([
                [0, [{ id: 1, parentId: 0 }]],
                [1, [childA, childB]],
            ]),
        });

        component.parentId = 1;

        expect(component.folders).toEqual([childA, childB]);
    });

    it('returns an empty array when a parent has no indexed children', () => {
        const component = makeComponent({
            _childrenByParent: new Map([[0, [{ id: 1, parentId: 0 }]]]),
        });

        component.parentId = 99;

        expect(component.folders).toEqual([]);
    });

    it('uses the indexed has-children set', () => {
        const component = makeComponent({
            _hasChildrenSet: new Set([4, 7]),
        });

        expect(component.hasChildren(4)).toBe(true);
        expect(component.hasChildren(6)).toBe(false);
        expect(component.hasChildren(0)).toBe(false);
    });

    it('treats pending new-folder form as children visibility', () => {
        const component = makeComponent({
            _hasChildrenSet: new Set(),
            newFolderParentId: 9,
        });

        expect(component.hasChildrenOrNewForm(9)).toBe(true);
        expect(component.hasChildrenOrNewForm(10)).toBe(false);
    });

    it('toggles folder selection instead of opening when select mode is active', () => {
        const component = makeComponent({
            folderSelectMode: true,
        });

        component.handleFolderClick({ id: 15, isProtected: false });

        expect(component.$store.plathix.toggleFolderSelected).toHaveBeenCalledWith(15);
        expect(component.$store.plathix.openFolder).not.toHaveBeenCalled();
    });

    it('opens protected folders even when select mode is active', () => {
        const component = makeComponent({
            folderSelectMode: true,
        });

        component.handleFolderClick({ id: 1, isProtected: true });

        expect(component.$store.plathix.openFolder).toHaveBeenCalledWith(1);
        expect(component.$store.plathix.toggleFolderSelected).not.toHaveBeenCalled();
    });

    describe('folderClasses has-context-menu sentinel', () => {




        it('does NOT mark system folder id=0 when context menu is closed (sentinel 0)', () => {
            const component = makeComponent({ contextMenuFolderId: 0, openId: 5 });

            expect(component.folderClasses({ id: 0 })['has-context-menu']).toBe(false);
        });

        it('marks a real folder when its context menu is open', () => {
            const component = makeComponent({ contextMenuFolderId: 5, openId: 0 });

            expect(component.folderClasses({ id: 5 })['has-context-menu']).toBe(true);
            expect(component.folderClasses({ id: 7 })['has-context-menu']).toBe(false);
        });

        it('keeps is-open valid for All-view (openId=0, folder id=0) — not touched by the fix', () => {
            const component = makeComponent({ contextMenuFolderId: 0, openId: 0 });
            const classes = component.folderClasses({ id: 0 });

            expect(classes['is-open']).toBe(true);
            expect(classes['has-context-menu']).toBe(false);
        });
    });

    describe('gates the bulk/drag-and-drop action behind the expected confirmation', () => {



        let confirmSpy;

        beforeEach(() => {
            confirmSpy = jest.spyOn(window, 'confirm');
            window.Plathix = { trashFolderId: 158 };
        });

        afterEach(() => {
            confirmSpy.mockRestore();
            delete window.Plathix;
        });

        function makeDropEvent(itemIds, folderEl) {
            const dataTransfer = {
                types: ['application/x-items'],
                _data: {},
                setData(type, value) { this._data[type] = value; },
                getData(type) { return this._data[type] || ''; },
            };
            dataTransfer.setData('application/x-items', JSON.stringify(itemIds));

            const event = new Event('drop', { bubbles: true, cancelable: true });
            Object.defineProperty(event, 'dataTransfer', { value: dataTransfer });
            Object.defineProperty(event, 'currentTarget', { value: folderEl, configurable: true });
            return event;
        }

        function makeFolderEl(id, name) {
            const el = document.createElement('div');
            el.className = 'plathix-folder';
            el.dataset.folderId = String(id);
            if (name) {
                const nameEl = document.createElement('span');
                nameEl.className = 'plathix-folder__name';
                nameEl.textContent = name;
                el.appendChild(nameEl);
            }
            document.body.appendChild(el);
            return el;
        }

        it('handles trash workflow consistently', () => {
            const moveItemsBulk = jest.fn();
            Alpine.store('plathix', {
                openId: 158,
                bulkSafeMode: false,
                isCurrentFolderTrashed: jest.fn(() => false),
                moveItemsBulk,
            });
            confirmSpy.mockReturnValue(true);

            const component = makeComponent();
            const folderEl = makeFolderEl(7, 'Target');
            const event = makeDropEvent([1], folderEl);

            component.handleDrop(event, 7);

            expect(confirmSpy).toHaveBeenCalledTimes(1);
            expect(moveItemsBulk).toHaveBeenCalledWith([1], 7);
        });

        it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
            const moveItemsBulk = jest.fn();
            Alpine.store('plathix', {
                openId: 158,
                bulkSafeMode: false,
                isCurrentFolderTrashed: jest.fn(() => false),
                moveItemsBulk,
            });
            confirmSpy.mockReturnValue(false);

            const component = makeComponent();
            const folderEl = makeFolderEl(7, 'Target');
            const event = makeDropEvent([1], folderEl);

            component.handleDrop(event, 7);

            expect(confirmSpy).toHaveBeenCalledTimes(1);
            expect(moveItemsBulk).not.toHaveBeenCalled();
        });

        it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
            const moveItemsBulk = jest.fn();
            Alpine.store('plathix', {
                openId: 3,
                bulkSafeMode: true,
                isCurrentFolderTrashed: jest.fn(() => false),
                moveItemsBulk,
            });
            confirmSpy.mockReturnValue(true);

            const component = makeComponent();
            const folderEl = makeFolderEl(7);
            const itemIds = Array.from({ length: 10 }, (_, i) => i + 1);
            const event = makeDropEvent(itemIds, folderEl);

            component.handleDrop(event, 7);

            expect(confirmSpy).toHaveBeenCalledTimes(1);
            expect(moveItemsBulk).toHaveBeenCalledWith(itemIds, 7);
        });

        it('handles trash workflow consistently', () => {
            const moveItemsBulk = jest.fn();
            Alpine.store('plathix', {
                openId: 3,
                bulkSafeMode: false,
                isCurrentFolderTrashed: jest.fn(() => false),
                moveItemsBulk,
            });

            const component = makeComponent();
            const folderEl = makeFolderEl(7);
            const event = makeDropEvent([1], folderEl);

            component.handleDrop(event, 7);

            expect(confirmSpy).not.toHaveBeenCalled();
            expect(moveItemsBulk).toHaveBeenCalledWith([1], 7);
        });

        it('keeps upload links scoped to the active folder', () => {
            const moveItemsBulk = jest.fn();
            Alpine.store('plathix', {
                openId: 3,
                bulkSafeMode: false,
                isCurrentFolderTrashed: jest.fn(() => false),
                moveItemsBulk,
            });

            const component = makeComponent();
            const folderEl = makeFolderEl(7);
            const event = new Event('drop', { bubbles: true, cancelable: true });
            Object.defineProperty(event, 'dataTransfer', {
                value: { types: ['Files'], getData: () => '' },
            });
            Object.defineProperty(event, 'currentTarget', { value: folderEl, configurable: true });

            component.handleDrop(event, 7);

            expect(confirmSpy).not.toHaveBeenCalled();
            expect(moveItemsBulk).not.toHaveBeenCalled();
        });
    });
});
