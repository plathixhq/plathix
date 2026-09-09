import { colorEditModule } from '../color-edit.js';
import { makeBaseStore } from '../test-base.js';
import { mergeStore } from '../utils.js';

jest.mock('../../api.js', () => ({
    Api: {
        setFolderColor: jest.fn(),
    },
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

import { Api } from '../../api.js';
import { cacheInvalidateFolder } from '../../static-list/cache.js';

function makeStore(extraState = {}) {
    const base = mergeStore(makeBaseStore(), colorEditModule);
    return Object.assign(Object.create(null), base, {
        folders: [],
        isLoading: false,
        error: null,
        async refreshFolders() {},
        ...extraState,
    });
}

describe('colorEditModule — setFolderColor', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('persists color and refreshes folders', async() => {
        Api.setFolderColor.mockResolvedValue({});
        const store = makeStore({ refreshFolders: jest.fn().mockResolvedValue({}) });

        await store.setFolderColor(4, '#ff0000');

        expect(Api.setFolderColor).toHaveBeenCalledWith(4, '#ff0000');
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(4);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('preserves folder tree behavior', async() => {
        let resolveApi;
        Api.setFolderColor.mockReturnValue(new Promise((r) => { resolveApi = r; }));
        const store = makeStore({
            folders: [{ id: 4, color: '#000000' }],
            refreshFolders: jest.fn().mockResolvedValue({}),
        });

        const pending = store.setFolderColor(4, '#ff0000');

        expect(store.folders[0].color).toBe('#ff0000');
        resolveApi({});
        await pending;
    });

    it('keeps store/selection state consistent across UI events', async() => {
        Api.setFolderColor.mockRejectedValue(new Error('rest fail'));
        const store = makeStore({
            folders: [{ id: 4, color: '#000000' }],
            refreshFolders: jest.fn().mockResolvedValue({}),
        });

        await expect(store.setFolderColor(4, '#ff0000')).rejects.toThrow('rest fail');
        expect(store.folders[0].color).toBe('#000000');
    });

    it('refreshes cached state when data changes', async() => {



        Api.setFolderColor.mockResolvedValue({});
        const store = makeStore({
            folders: [{ id: 4, color: '#000000' }],
            refreshFolders: jest.fn().mockRejectedValue(new Error('network error')),
        });

        await store.setFolderColor(4, '#ff0000');

        expect(store.folders[0].color).toBe('#ff0000');
    });
});
