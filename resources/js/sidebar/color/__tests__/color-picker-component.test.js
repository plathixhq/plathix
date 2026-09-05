import { colorPickerComponent } from '../color-picker-component.js';

/**
 * Пикер: локальное реактивное поле color (превью), синхронизируемое из store через
 * syncFromStore() ([internal] — возврат до-атомизационного механизма, источник = store).
 */
function make(storeState = {}) {
    const store = {
        contextMenuFolderId: 0,
        folders: [],
        setFolderColor: jest.fn(),
        ...storeState,
    };
    return Object.assign(Object.create(colorPickerComponent()), { $store: { plathix: store } }, { color: '#2271b1' });
}

describe('colorPickerComponent — локальный color + syncFromStore из store', () => {
    it('syncFromStore: ставит локальный color из цвета текущей папки по contextMenuFolderId', () => {
        const c = make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '#ff8800' }] });
        c.syncFromStore();
        expect(c.color).toBe('#ff8800');
    });

    it('syncFromStore: папка без цвета → дефолт', () => {
        const c = make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '' }] });
        c.syncFromStore();
        expect(c.color).toBe('#2271b1');
    });

    it('hasColor: true если у текущей папки задан цвет, иначе false', () => {
        expect(make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '#abcdef' }] }).hasColor).toBe(true);
        expect(make({ contextMenuFolderId: 5, folders: [{ id: 5, color: '' }] }).hasColor).toBe(false);
    });

    it('set(): пишет в локальный color (мгновенное превью) И в store по id текущей папки', () => {
        const c = make({ contextMenuFolderId: 7, folders: [{ id: 7, color: '' }] });
        c.set('AABBCC');
        expect(c.color).toBe('#aabbcc');
        expect(c.$store.plathix.setFolderColor).toHaveBeenCalledWith(7, '#aabbcc');
    });

    it('set(): невалидный обрубок — не пишет ни в color, ни в store', () => {
        const c = make({ contextMenuFolderId: 7, folders: [{ id: 7, color: '' }] });
        c.color = '#111111';
        c.set('12345');
        expect(c.color).toBe('#111111');
        expect(c.$store.plathix.setFolderColor).not.toHaveBeenCalled();
    });

    it('set(): без открытой папки (id=0) не пишет', () => {
        const c = make({ contextMenuFolderId: 0, folders: [] });
        c.set('#ff0000');
        expect(c.$store.plathix.setFolderColor).not.toHaveBeenCalled();
    });
});
