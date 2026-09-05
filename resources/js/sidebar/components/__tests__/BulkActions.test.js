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

describe('BulkActions.createRootFolder — идемпотентность верхней «+» ([internal])', () => {
    it('открывает форму, когда форма ещё не открыта', () => {
        // openId=5 (не системная) → parentId=5
        const { component, store } = makeComponent({ newFolderParentId: null });
        component.createRootFolder();
        expect(store.showNewFolderForm).toHaveBeenCalledWith(5);
        expect(store.focusNewFolderInput).not.toHaveBeenCalled();
    });

    it('перефокусирует (НЕ скрывает) при повторном клике по той же «+»', () => {
        // форма уже открыта на том же parentId=5 → повторная «+» = перефокус
        const { component, store } = makeComponent({ newFolderParentId: 5 });
        component.createRootFolder();
        expect(store.focusNewFolderInput).toHaveBeenCalledTimes(1);
        expect(store.hideNewFolderForm).not.toHaveBeenCalled();
        expect(store.showNewFolderForm).not.toHaveBeenCalled();
    });

    it('переоткрывает при смене контекста (открыта форма другого parentId)', () => {
        // открыта форма подпапки parentId=99, жмут корневую «+» → целевой parentId=5 ≠ 99
        const { component, store } = makeComponent({ newFolderParentId: 99 });
        component.createRootFolder();
        expect(store.showNewFolderForm).toHaveBeenCalledWith(5);
        expect(store.focusNewFolderInput).not.toHaveBeenCalled();
    });

    it('в системной папке целевой parentId=0 и перефокус при открытой форме id=0', () => {
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

    it('delegates to confirmAndMoveItems even when current folder is Trash ([internal]: trash-restore confirm is confirmAndMoveItems\' responsibility, not BulkActions\')', () => {
        const { component, store } = makeComponent({
            openId: 158,
            isCurrentFolderTrashed: jest.fn(() => true),
        });
        component.moveSelected(7);
        expect(confirmAndMoveItems).toHaveBeenCalledWith([1, 2, 3], 7, null);
        // BulkActions больше не решает, показывать ли confirm — это делает confirmAndMoveItems.
        expect(store.moveItemsBulk).not.toHaveBeenCalled();
    });
});

