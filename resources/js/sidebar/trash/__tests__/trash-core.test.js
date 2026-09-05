/**
 * [internal]: onPurgeClick не обновлял счётчики дерева/заголовка после окончательного
 * удаления папки — только локально перерисовывал панель. onRestoreClick уже вызывал
 * store.refreshFolders(); этот тест фиксирует симметрию (контроль-контракт: убрать
 * refreshFolders из onPurgeClick → тест краснеет).
 */
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

describe('trash-core.js — refreshFolders симметрия restore/purge ([internal])', () => {
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

    it('onPurgeClick вызывает store.refreshFolders после успешного Api.purgeFolder', async () => {
        await onPurgeClick(btn, store, container);

        expect(Api.purgeFolder).toHaveBeenCalledWith(42);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(42);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('onPurgeClick не падает, если store отсутствует (optional chaining)', async () => {
        await expect(onPurgeClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(Api.purgeFolder).toHaveBeenCalledWith(42);
    });

    it('onRestoreClick продолжает вызывать store.refreshFolders (regression, уже работало)', async () => {
        await onRestoreClick(btn, store, container);

        expect(Api.restoreFolder).toHaveBeenCalledWith(42);
        expect(cacheInvalidateFolder).toHaveBeenCalledWith(42);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true, skipCacheClear: true });
    });

    it('onPurgeClick вызывает refreshFolders ДО перерисовки панели (тот же порядок, что onRestoreClick)', async () => {
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

describe('trash-core.js — restore/purge честно сообщают об ошибке ([internal])', () => {
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

    it('onRestoreClick вызывает store.notify(error) и возвращает кнопку в исходное состояние при сбое Api.restoreFolder', async () => {
        Api.restoreFolder.mockRejectedValueOnce(new Error('network'));

        await onRestoreClick(btn, store, container);

        expect(store.notify).toHaveBeenCalledWith('error', 'folder could not be restored');
        expect(btn.disabled).toBe(false);
    });

    it('onRestoreClick не падает, если store отсутствует, при сбое Api.restoreFolder', async () => {
        Api.restoreFolder.mockRejectedValueOnce(new Error('network'));

        await expect(onRestoreClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(btn.disabled).toBe(false);
    });

    it('onPurgeClick вызывает store.notify(error) и возвращает кнопку в исходное состояние при сбое Api.purgeFolder', async () => {
        Api.purgeFolder.mockRejectedValueOnce(new Error('network'));

        await onPurgeClick(btn, store, container);

        expect(store.notify).toHaveBeenCalledWith('error', 'folder could not be deleted permanently');
        expect(btn.disabled).toBe(false);
    });

    it('onPurgeClick не падает, если store отсутствует, при сбое Api.purgeFolder', async () => {
        Api.purgeFolder.mockRejectedValueOnce(new Error('network'));

        await expect(onPurgeClick(btn, undefined, container)).resolves.toBeUndefined();
        expect(btn.disabled).toBe(false);
    });
});

describe('trash-core.js — кеш id удалённых папок для toolbar ([internal])', () => {
    beforeEach(async () => {
        jest.clearAllMocks();
        Api.getTrashedFolders.mockResolvedValue({ folders: [] });
        // Сбросить module-level кеш до пустого состояния перед каждым тестом.
        await refreshTrashedFolderIds();
    });

    it('refreshTrashedFolderIds заполняет кеш id из Api.getTrashedFolders', async () => {
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 7 }, { id: '12' }] });

        const ids = await refreshTrashedFolderIds();

        expect(ids.has(7)).toBe(true);
        expect(ids.has(12)).toBe(true);
        expect(getCachedTrashedFolderIds()).toBe(ids);
    });

    it('refreshTrashedFolderIds не трогает кеш при сетевой ошибке (безопасный дефолт)', async () => {
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 7 }] });
        await refreshTrashedFolderIds();
        expect(getCachedTrashedFolderIds().has(7)).toBe(true);

        Api.getTrashedFolders.mockRejectedValue(new Error('network'));
        await refreshTrashedFolderIds();

        expect(getCachedTrashedFolderIds().has(7)).toBe(true);
    });

    it('fetchAndRenderTiles синхронизирует тот же кеш, что и refreshTrashedFolderIds ([internal])', async () => {
        const container = document.createElement('div');
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 55, name: 'X' }] });

        await fetchAndRenderTiles(container, { refreshFolders: jest.fn() });

        expect(getCachedTrashedFolderIds().has(55)).toBe(true);
    });

    it('refreshTrashedFolderIds уведомляет подписчиков onTrashedFolderIdsChange ([internal], browser-proof regression)', async () => {
        // Найдено на live stand: Alpine x-show не переоценивает isCurrentFolderTrashed(),
        // если кеш обновился без явного уведомления — этот listener и есть механизм,
        // которым trash-entry.js триггерит Alpine-реактивность (_trashedFolderIdsVersion++).
        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);

        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 9 }] });
        await refreshTrashedFolderIds();

        expect(listener).toHaveBeenCalledTimes(1);
        unsubscribe();
    });

    it('fetchAndRenderTiles тоже уведомляет подписчиков onTrashedFolderIdsChange ([internal])', async () => {
        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);
        const container = document.createElement('div');
        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 10 }] });

        await fetchAndRenderTiles(container, { refreshFolders: jest.fn() });

        expect(listener).toHaveBeenCalledTimes(1);
        unsubscribe();
    });

    it('unsubscribe возвращённый onTrashedFolderIdsChange останавливает уведомления', async () => {
        const listener = jest.fn();
        const unsubscribe = onTrashedFolderIdsChange(listener);
        unsubscribe();

        Api.getTrashedFolders.mockResolvedValue({ folders: [{ id: 11 }] });
        await refreshTrashedFolderIds();

        expect(listener).not.toHaveBeenCalled();
    });
});

describe('trash-core.js — escapeAttr защищает title от stored XSS ([internal])', () => {
    it('экранирует двойную кавычку — не позволяет разорвать атрибут через "', () => {
        const result = escapeAttr('x" onmouseover="alert(1)');

        expect(result).not.toContain('"');
        expect(result).toContain('&quot;');
    });

    it('экранирует одинарную кавычку — не позволяет разорвать атрибут через \'', () => {
        const result = escapeAttr("x' onfocus='alert(1)");

        expect(result).not.toContain("'");
        expect(result).toContain('&#39;');
    });

    it('экранирует амперсанд без двойного эскейпинга последующих символов', () => {
        const result = escapeAttr('Tom & Jerry "Show"');

        expect(result).toBe('Tom &amp; Jerry &quot;Show&quot;');
    });

    it('сохраняет обычные спецсимволы визуально узнаваемыми (обязательный regression-набор issue)', () => {
        expect(escapeAttr('Клиент "A&B" <архив> 📁')).toBe('Клиент &quot;A&amp;B&quot; &lt;архив&gt; 📁');
        expect(escapeAttr("O'Reilly / Design 2026")).toBe('O&#39;Reilly / Design 2026');
    });

    it('tileHtml для payload-имени папки не содержит рабочего onmouseover-атрибута в title', () => {
        const html = tileHtml({ id: 1, name: 'x" onmouseover="alert(1)', color: '', kids: 0, deletedAt: 0 });
        const container = document.createElement('div');
        container.innerHTML = html;

        const tname = container.querySelector('.plathix-folder-trash-panel__tname');
        expect(tname.getAttribute('title')).toBe('x" onmouseover="alert(1)');
        expect(tname.getAttribute('onmouseover')).toBeNull(); // payload не стал отдельным рабочим атрибутом
    });

    it('tileHtml для payload-имени папки с одинарной кавычкой тоже не пробивает атрибут', () => {
        const html = tileHtml({ id: 2, name: "x' onfocus='alert(1)", color: '', kids: 0, deletedAt: 0 });
        const container = document.createElement('div');
        container.innerHTML = html;

        const tname = container.querySelector('.plathix-folder-trash-panel__tname');
        expect(tname.getAttribute('title')).toBe("x' onfocus='alert(1)");
        expect(tname.getAttribute('onfocus')).toBeNull(); // payload не стал отдельным рабочим атрибутом
    });
});
