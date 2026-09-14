import { contextMenuComponent } from '../ContextMenu.js';

function makeComponent(storeOverrides = {}) {
    const component = contextMenuComponent();
    component.$store = {
        plathix: {
            contextMenuFolderId: 0,
            showNewFolderForm: jest.fn(),
            showRenameForm: jest.fn(),
            showDeleteConfirm: jest.fn(),
            ...storeOverrides,
        },
    };
    component.$el = null;
    component.$nextTick = (fn) => fn();
    return component;
}

function makeOpenPayload(folder, overrides = {}) {
    return {
        folder,
        folderEl: null,
        event: { target: document.createElement('div'), clientX: 10, clientY: 20 },
        ...overrides,
    };
}

describe('handles trash workflow consistently', () => {
    let triggerButton;

    beforeEach(() => {
        triggerButton = document.createElement('button');
        document.body.appendChild(triggerButton);
        triggerButton.focus();
    });

    afterEach(() => {
        triggerButton.remove();
    });

    it('open() captures the currently focused element as opener', () => {
        const component = makeComponent();

        component.open(makeOpenPayload({ id: 10, name: 'Work' }));

        expect(component._opener).toBe(triggerButton);
        expect(component.isOpen).toBe(true);
    });

    it('open() does not capture opener when the folder is protected (menu never opens)', () => {
        const component = makeComponent();

        component.open(makeOpenPayload({ id: 10, name: 'Trash', isProtected: true }));

        expect(component._opener).toBeNull();
        expect(component.isOpen).toBe(false);
    });

    it('close() restores focus to the opener and clears it', () => {
        const component = makeComponent();
        component.open(makeOpenPayload({ id: 10, name: 'Work' }));
        document.activeElement.blur();

        component.close();

        expect(document.activeElement).toBe(triggerButton);
        expect(component._opener).toBeNull();
        expect(component.isOpen).toBe(false);
    });

    it('remove() delegates to showDeleteConfirm and restores focus via close()', () => {
        const component = makeComponent();
        const folder = { id: 10, name: 'Work' };
        component.open(makeOpenPayload(folder));
        document.activeElement.blur();

        component.remove();

        expect(component.$store.plathix.showDeleteConfirm).toHaveBeenCalledWith(folder);
        expect(document.activeElement).toBe(triggerButton);
        expect(component._opener).toBeNull();
    });

    it('rename() delegates to showRenameForm and restores focus via close()', () => {
        const component = makeComponent();
        const folder = { id: 10, name: 'Work' };
        component.open(makeOpenPayload(folder));
        document.activeElement.blur();

        component.rename();

        expect(component.$store.plathix.showRenameForm).toHaveBeenCalledWith(folder);
        expect(document.activeElement).toBe(triggerButton);
    });

    it('createSubfolder() delegates to showNewFolderForm and restores focus via close()', () => {
        const component = makeComponent();
        const folder = { id: 10, name: 'Work' };
        component.open(makeOpenPayload(folder));
        document.activeElement.blur();

        component.createSubfolder();

        expect(component.$store.plathix.showNewFolderForm).toHaveBeenCalledWith(10);
        expect(document.activeElement).toBe(triggerButton);
    });
});
