

import { onPurgeClick, onRestoreClick, getCachedTrashedFolderIds, refreshTrashedFolderIds, fetchAndRenderTiles, onTrashedFolderIdsChange, escapeAttr, tileHtml } from '../trash-core.js';

jest.mock('../../api.js', () => ({
    Api: {
        purgeFolder: jest.fn(() => Promise.resolve({})),
        restoreFolder: jest.fn(() => Promise.resolve({})),
        getTrashedFolders: jest.fn(() => Promise.resolve({ folders: [] })),
    },
}));

jest.mock('../../i18n.js', () => ({ t: (_key, fallback) => fallback }));

jest.mock('../../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
}));

import { Api } from '../../api.js';
import { cacheInvalidateFolder } from '../../static-list/cache.js';

describe('handles trash workflow consistently', () => {
    let btn;
    let container;
    let store;
    let confirmSpy;

    beforeEach(() => {
        btn = document.createElement('button');
        btn.setAttribute('data-id', '42');
        container = document.createElement('div');
        store = { refreshFolders: jest.fn(() => Promise.resolve()), notify: jest.fn() };
        confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
        jest.clearAllMocks();
    });

    afterEach(() => {
        confirmSpy.mockRestore();
    });

    it('handles trash workflow consistently', async () => {
        await onPurgeClick(btn, store, container);

        expect(Api.purgeFolder).toHaveBeenCalledWith(42);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(42);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('handles trash workflow consistently', async () => {
        await expect(onPurgeClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(Api.purgeFolder).toHaveBeenCalledWith(42);
    });

    it('handles trash workflow consistently', async () => {
        await onRestoreClick(btn, store, container);

        expect(Api.restoreFolder).toHaveBeenCalledWith(42);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(42);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('handles trash workflow consistently', async () => {
        const callOrder = [];
        store.refreshFolders = jest.fn(() => {
            callOrder.push('refreshFolders');
            return Promise.resolve();
        });
        Api.purgeFolder.mockImplementation(() => {
            callOrder.push('purgeFolder');
            return Promise.resolve({});
        });

        await onPurgeClick(btn, store, container);

        expect(callOrder).toEqual(['purgeFolder', 'refreshFolders']);
    });
});

describe('handles trash workflow consistently', () => {
    let btn;
    let container;
    let store;
    let confirmSpy;

    beforeEach(() => {
        btn = document.createElement('button');
        btn.setAttribute('data-id', '42');
        container = document.createElement('div');
        store = { refreshFolders: jest.fn(() => Promise.resolve()), notify: jest.fn() };
        confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
        jest.clearAllMocks();
    });

    afterEach(() => {
        confirmSpy.mockRestore();
    });

    it('handles trash workflow consistently', async () => {
        Api.restoreFolder.mockRejectedValueOnce(new Error('network'));

        await onRestoreClick(btn, store, container);

        expect(store.notify).toHaveBeenCalledWith('error', 'folder could not be restored');
        expect(btn.disabled).toBe(false);
    });

    it('handles trash workflow consistently', async () => {
        Api.restoreFolder.mockRejectedValueOnce(new Error('network'));

        await expect(onRestoreClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(btn.disabled).toBe(false);
    });

    it('handles trash workflow consistently', async () => {
        Api.purgeFolder.mockRejectedValueOnce(new Error('network'));

        await onPurgeClick(btn, store, container);

        expect(store.notify).toHaveBeenCalledWith('error', 'folder could not be deleted permanently');
        expect(btn.disabled).toBe(false);
    });

    it('handles trash workflow consistently', async () => {
        Api.purgeFolder.mockRejectedValueOnce(new Error('network'));

        await expect(onPurgeClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(btn.disabled).toBe(false);
    });
});

describe('handles trash workflow consistently', () => {
    beforeEach(async () => {
        jest.clearAllMocks();
        Api.getTrashedFolders.mockResolvedValue({ folders: [] });

        await refreshTrashedFolderIds();
    });

    it('handles trash workflow consistently', async () => {
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 7 }, { id: '12' }] });

        const ids = await refreshTrashedFolderIds();

        expect(ids.has(7)).toBe(true);
        expect(ids.has(12)).toBe(true);
        expect(getCachedTrashedFolderIds()).toBe(ids);
    });

    it('handles trash workflow consistently', async () => {
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 7 }] });
        await refreshTrashedFolderIds();
        expect(getCachedTrashedFolderIds().has(7)).toBe(true);

        Api.getTrashedFolders.mockRejectedValue(new Error('network'));
        await refreshTrashedFolderIds();

        expect(getCachedTrashedFolderIds().has(7)).toBe(true);
    });

    it('handles trash workflow consistently', async () => {
        const container = document.createElement('div');
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 55, name: 'X' }] });

        await fetchAndRenderTiles(container, { refreshFolders: jest.fn() });

        expect(getCachedTrashedFolderIds().has(55)).toBe(true);
    });

    it('handles trash workflow consistently', async () => {



        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);

        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 9 }] });
        await refreshTrashedFolderIds();

        expect(listener).toHaveBeenCalledTimes(1);
        unsubscribe();
    });

    it('handles trash workflow consistently', async () => {
        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);
        const container = document.createElement('div');
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 10 }] });

        await fetchAndRenderTiles(container, { refreshFolders: jest.fn() });

        expect(listener).toHaveBeenCalledTimes(1);
        unsubscribe();
    });

    it('handles trash workflow consistently', async () => {
        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);
        unsubscribe();

        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 11 }] });
        await refreshTrashedFolderIds();

        expect(listener).not.toHaveBeenCalled();
    });
});

describe('handles trash workflow consistently', () => {
    it('covers public behavior without internal references', () => {
        const result = escapeAttr('x" onmouseover="alert(1)');

        expect(result).not.toContain('"');
        expect(result).toContain('&quot;');
    });

    it('covers public behavior without internal references', () => {
        const result = escapeAttr("x' onfocus='alert(1)");

        expect(result).not.toContain("'");
        expect(result).toContain('&#39;');
    });

    it('covers public behavior without internal references', () => {
        const result = escapeAttr('Tom & Jerry "Show"Clients"A&B"Clients"O'Reilly / Design 2026")).toBe('O&#39;Reilly / Design 2026');
    });

    it('keeps upload links scoped to the active folder', () => {
        const html = tileHtml({ id: 1, name: 'x" onmouseover="alert(1)', color: '', kids: 0, deletedAt: 0 });
        const container = document.createElement('div');
        container.innerHTML = html;

        const tname = container.querySelector('.plathix-folder-trash-panel__tname');
        expect(tname.getAttribute('title')).toBe('x" onmouseover="alert(1)');
        expect(tname.getAttribute('onmouseover')).toBeNull();
    });

    it('keeps upload links scoped to the active folder', () => {
        const html = tileHtml({ id: 2, name: "x' onfocus='alert(1)", color: '', kids: 0, deletedAt: 0 });
        const container = document.createElement('div');
        container.innerHTML = html;

        const tname = container.querySelector('.plathix-folder-trash-panel__tname');
        expect(tname.getAttribute('title')).toBe("x' onfocus='alert(1)");
        expect(tname.getAttribute('onfocus')).toBeNull();
    });
});
