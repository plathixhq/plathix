import { colorPickerComponent } from '../color-picker-component.js';



function make(storeState = {}) {
    const store = {
        contextMenuFolderId: 0,
        folders: [],
        setFolderColor: jest.fn(),
        ...storeState,
    };
    return Object.assign(Object.create(colorPickerComponent()), { $store: { plathix: store } }, { color: '#2271b1' });
}

describe('keeps store/selection state consistent across UI events', () => {
    it('keeps upload links scoped to the active folder', () => {
        const c = make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '#ff8800' }] });
        c.syncFromStore();
        expect(c.color).toBe('#ff8800');
    });

    it('keeps upload links scoped to the active folder', () => {
        const c = make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '' }] });
        c.syncFromStore();
        expect(c.color).toBe('#2271b1');
    });

    it('keeps upload links scoped to the active folder', () => {
        expect(make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '#abcdef' }] }).hasColor).toBe(true);
        expect(make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '' }] }).hasColor).toBe(false);
    });

    it('keeps upload links scoped to the active folder', () => {
        const c = make({ contextMenuFolderId: 7, folders: [{ id: 7, color: '' }] });
        c.set('AABBCC');
        expect(c.color).toBe('#aabbcc');
        expect(c.$store.plathix.setFolderColor).toHaveBeenCalledWith(7, '#aabbcc');
    });

    it('keeps store/selection state consistent across UI events', () => {
        const c = make({ contextMenuFolderId: 7, folders: [{ id: 7, color: '' }] });
        c.color = '#111111';
        c.set('12345');
        expect(c.color).toBe('#111111');
        expect(c.$store.plathix.setFolderColor).not.toHaveBeenCalled();
    });

    it('keeps upload links scoped to the active folder', () => {
        const c = make({ contextMenuFolderId: 0, folders: [] });
        c.set('#ff0000');
        expect(c.$store.plathix.setFolderColor).not.toHaveBeenCalled();
    });
});
