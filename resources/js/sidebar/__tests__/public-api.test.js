jest.mock('alpinejs', () => ({ store: jest.fn() }));
jest.mock('../api.js', () => ({
    Api: {
        getFolders: jest.fn(),
        getFolderItems: jest.fn(),
        createFolder: jest.fn(),
        renameFolder: jest.fn(),
        deleteFolder: jest.fn(),
        setFolderColor: jest.fn(),
        moveFolderParent: jest.fn(),
        moveItemsBulk: jest.fn(),
        reorderTree: jest.fn(),
        savePreference: jest.fn(),
    },
    DEFAULT_ON_CHILDREN: 'delete',
}));
jest.mock('../folder-selector.js', () => ({
    createFolderSelector: jest.fn(),
}));

import Alpine from 'alpinejs';
import { Api } from '../api.js';
import { createFolderSelector } from '../folder-selector.js';
import { createPublicApi, installPublicApi } from '../public-api.js';

describe('public api facade', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        delete window.PlathixApi;
        delete window.__PlathixApiReady;
    });






    it('does not forward params to the store — only the silent flag', async () => {
        const store = { refreshFolders: jest.fn() };
        Alpine.store.mockReturnValue(store);

        const facade = createPublicApi();
        await facade.refreshFolders({ silent: true, params: { fields: 'id,count' } });

        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
    });

    it('proxies calls to Api methods', async () => {
        const store = {
            openId: 7,
            selected: [3],
            folders: [{ id: 7 }],
            isLoading: false,
            isUploading: false,
            refreshFolders: jest.fn(),
            openFolder: jest.fn(),
        };
        Alpine.store.mockReturnValue(store);
        Api.savePreference.mockResolvedValue({ ok: true });
        createFolderSelector.mockResolvedValue({ getValue: () => 7 });




        const facade = createPublicApi();
        await facade.savePreference('view_mode', 'grid');
        await facade.refreshFolders({ silent: true });
        await facade.openFolder(9);
        await facade.createFolderSelector('#mount', { includeAll: true });

        expect(Api.savePreference).toHaveBeenCalledWith('view_mode', 'grid');
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
        expect(store.openFolder).toHaveBeenCalledWith(9);
        expect(createFolderSelector).toHaveBeenCalledWith('#mount', { includeAll: true });
        expect(facade.getState()).toEqual({
            openId: 7,
            selected: [3],
            folders: [{ id: 7 }],
            isLoading: false,
            isUploading: false,
        });
    });

    

    it('exposes exactly the documented facade surface', () => {
        const EXPECTED = [
            'getStore',
            'getState',
            'onReady',
            'refreshFolders',
            'openFolder',
            'getFolders',
            'getFolderItems',
            'createFolder',
            'renameFolder',
            'deleteFolder',
            'setFolderColor',
            'moveFolderParent',
            'moveItemsBulk',
            'reorderTree',
            'savePreference',
            'createFolderSelector',
        ];

        expect(Object.keys(createPublicApi()).sort()).toEqual([...EXPECTED].sort());
    });

    it('resolves onReady immediately when api is already ready', () => {
        window.__PlathixApiReady = true;
        const callback = jest.fn();
        const facade = installPublicApi();

        facade.onReady(callback);

        expect(callback).toHaveBeenCalledWith(window.PlathixApi);
    });

    it('registers global facade once', () => {
        const first = installPublicApi();
        const second = installPublicApi();

        expect(first).toBe(second);
        expect(window.PlathixApi).toBe(first);
    });
});
