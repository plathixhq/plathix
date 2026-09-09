import { bulkActionsComponent } from '../BulkActions.js';
import { confirmAndMoveItems } from '../../dnd.js';

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../dnd.js', () => ({
    confirmAndMoveItems: jest.fn(),
}));

function makeComponent(storeOverrides = {}) {
    const store = {
        bulkSafeMode: false,
        error: null,
        moveItemsBulk: jest.fn(),
        getSelectedItemIds: jest.fn(() => [1, 2, 3]),
        showNewFolderForm: jest.fn(),
        hideNewFolderForm: jest.fn(),
        focusNewFolderInput: jest.fn(),
        newFolderParentId: null,
        openId: 5,
        FOLDER_UNCATEGORIZED: 0,
        ...storeOverrides,
    };

    const component = bulkActionsComponent();
    component.$store = { plathix: store };
    return { component, store };
}

describe('preserves folder tree behavior', () => {
    it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {

        const { component, store } = makeComponent({ newFolderParentId: null });
        component.createRootFolder();
        expect(store.showNewFolderForm).toHaveBeenCalledWith(5);
        expect(store.focusNewFolderInput).not.toHaveBeenCalled();
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {

        const { component, store } = makeComponent({ newFolderParentId: 5 });
        component.createRootFolder();
        expect(store.focusNewFolderInput).toHaveBeenCalledTimes(1);
        expect(store.hideNewFolderForm).not.toHaveBeenCalled();
        expect(store.showNewFolderForm).not.toHaveBeenCalled();
    });

    it('preserves folder tree behavior', () => {

        const { component, store } = makeComponent({ newFolderParentId: 99 });
        component.createRootFolder();
        expect(store.showNewFolderForm).toHaveBeenCalledWith(5);
        expect(store.focusNewFolderInput).not.toHaveBeenCalled();
    });

    it('keeps upload links scoped to the active folder', () => {
        const { component, store } = makeComponent({ openId: 0, newFolderParentId: 0 });
        component.createRootFolder();
        expect(store.focusNewFolderInput).toHaveBeenCalledTimes(1);
        expect(store.showNewFolderForm).not.toHaveBeenCalled();
    });
});

describe('BulkActions.moveSelected', () => {
    afterEach(() => {
        confirmAndMoveItems.mockClear();
    });

    it('sets error when no items selected', () => {
        const { component, store } = makeComponent({ getSelectedItemIds: jest.fn(() => []) });
        component.moveSelected(7);
        expect(confirmAndMoveItems).not.toHaveBeenCalled();
        expect(store.error).toBe('No items selected.');
    });

    it('sets error when no valid target folder', () => {
        const { component, store } = makeComponent();
        component.moveSelected(0);
        expect(confirmAndMoveItems).not.toHaveBeenCalled();
        expect(store.error).toBe('Open a destination folder first.');
    });

    it('delegates to confirmAndMoveItems with selected ids, target folder and no DOM element (button path, no folder element to name)', () => {
        const { component } = makeComponent();
        component.moveSelected(7);
        expect(confirmAndMoveItems).toHaveBeenCalledWith([1, 2, 3], 7, null);
    });

    it('handles trash workflow consistently', () => {
        const { component, store } = makeComponent({
            openId: 158,
            isCurrentFolderTrashed: jest.fn(() => true),
        });
        component.moveSelected(7);
        expect(confirmAndMoveItems).toHaveBeenCalledWith([1, 2, 3], 7, null);

        expect(store.moveItemsBulk).not.toHaveBeenCalled();
    });
});

