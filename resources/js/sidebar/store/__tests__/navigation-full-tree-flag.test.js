/**
 * [internal] ([internal]).
 *
 * `refreshFolders` — владелец сетевой загрузки дерева: он знает `replace`, знает `params`
 * и знает, что положил в `this.folders`. Флаг полноты `hasLoadedFullTree` обязан выводиться
 * из этого факта, а не требоваться от каждого из 13 вызывающих (11 из них его не передают,
 * из-за чего флаг залипает в false до конца сессии).
 *
 * Отдельный файл, а не блок в navigation.test.js: `tree-state.js` читает `getRuntime()` на
 * уровне модуля (tree-state.js:3,10-11), поэтому lazy-режим (`deferFoldersBootstrap: true`)
 * должен быть в моке runtime ДО импорта — общий мок соседнего файла этого не даёт.
 *
 * `tree-state.js` и `navigation.js` здесь НЕ мокаются намеренно (launch.md, mocks policy):
 * инвариант живёт в связке «params → вывод во владельце → флаг → hasLoadedChildren»,
 * и подмена любого звена превратила бы тест в проверку формы вызова вместо контракта.
 */

import { navigationModule } from '../navigation.js';
import { treeStateModule } from '../tree-state.js';
import { mergeStore } from '../utils.js';

jest.mock('alpinejs', () => ({
    nextTick: jest.fn((cb) => cb()),
}));

jest.mock('../../api.js', () => ({
    Api: {
        getFolders: jest.fn(),
        getFolderCount: jest.fn(),
        savePreference: jest.fn(),
    },
}));

jest.mock('../../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../../hooks.js', () => ({
    doAction: jest.fn(),
}));

// deferFoldersBootstrap: true — lazy-режим (>200 папок, SidebarRuntimeConfigBuilder.php:45-47).
// Именно в нём hasLoadedFullTree стартует как false (tree-state.js:10) и залипает.
jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(() => null),
    getPostType: jest.fn(() => 'attachment'),
    getRuntime: jest.fn(() => ({ trashFolderId: 999, deferFoldersBootstrap: true })),
    shouldUseStaticListFiltering: jest.fn(() => false),
    shouldUseMediaFrameFiltering: jest.fn(() => false),
}));

jest.mock('../../static-list/index.js', () => ({
    getStaticListManager: jest.fn(() => null),
}));

jest.mock('../../static-list/cache.js', () => ({
    cacheClear: jest.fn(),
}));

import { Api } from '../../api.js';

const FULL_TREE = [
    { id: 1, name: 'A', parentId: 0, hasChildren: true },
    { id: 2, name: 'B', parentId: 1, hasChildren: false },
];

function makeStore(extraState = {})
{
    const base = mergeStore(navigationModule, treeStateModule);
    return Object.assign(Object.create(null), base, {
        folders: [],
        openId: 0,
        isLoading: false,
        error: null,
        applyFolderFilter: jest.fn(),
        notify: jest.fn(),
        // Свежее состояние lazy-режима на каждый тест: treeStateModule вычисляет эти поля
        // один раз при импорте, а Set — мутабелен и протёк бы между тестами.
        hasLoadedFullTree: false,
        loadedParentIds: new Set([0]),
        ...extraState,
    });
}

/** Промис с внешним resolve — чтобы задать порядок прихода ответов в гоночном тесте. */
function deferred()
{
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });
    return { promise, resolve };
}

describe('refreshFolders — вывод hasLoadedFullTree из факта загрузки ([internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        Api.getFolders.mockResolvedValue({ folders: FULL_TREE });
    });

    // Тест 1 — ядро бага. Мутационные вызовы (items.js:131, folder-move.js:44,52,
    // bulk-delete.js:55, folders-crud.js:266,287 и ещё 7) выглядят ровно так.
    it('чистый вызов без params помечает дерево полным', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });

        expect(store.folders).toEqual(FULL_TREE);
        expect(store.hasLoadedFullTree).toBe(true);
    });

    // Тест 2 — наблюдаемое следствие. Пока флаг занижен, guard в loadFolderChildren
    // (navigation.js:71-73) не срабатывает и уходит лишний REST-запрос за уже
    // имеющимися данными.
    it('после такого вызова loadFolderChildren не делает повторный запрос', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });
        Api.getFolders.mockClear();
        const result = await store.loadFolderChildren(1);

        expect(result).toBeNull();
        expect(Api.getFolders).not.toHaveBeenCalled();
    });

    // Тесты 3-5 — инварианты, которые правка обязана сохранить (проходят и до неё).

    it('частичная догрузка (parent_id + replace:false) не помечает дерево полным', async() => {
        const store = makeStore();

        await store.loadFolderChildren(1);

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('явный markFullTree:false имеет приоритет над выводом', async() => {
        const store = makeStore({ hasLoadedFullTree: true });

        await store.refreshFolders({ silent: true, markFullTree: false });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    // Четыре исключения — зеркало параметров FolderReadController.php:30-41. Без них вывод
    // был бы ЛОЖНЫМ: сервер режет результат внутри той же full-tree ветки, а завышенный флаг
    // опаснее заниженного — hasLoadedChildren() начал бы отвечать true на незагруженные
    // ветки, и loadFolderChildren замолчал бы навсегда.

    it('params.search не помечает дерево полным (сервер фильтрует состав)', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { search: 'foo' } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('params.ids не помечает дерево полным (сервер фильтрует состав)', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { ids: [1, 2] } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('params.fields не помечает дерево полным (сервер режет поля)', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, params: { fields: 'id,count' } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('params.parent_id не помечает дерево полным даже при replace:true', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, replace: true, params: { parent_id: 1 } });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    // buildQuery (api/transport.js:199) отбрасывает undefined/null/'' и пустые массивы —
    // такой ключ до сервера не доедет, ответ будет полным деревом. Проверка по значению,
    // а не по наличию ключа, иначе здесь был бы ложноотрицательный вывод.
    it('пустые значения params не мешают выводу — запрос физически полный', async() => {
        const store = makeStore();

        await store.refreshFolders({
            silent: true,
            params: { search: '', ids: [], fields: undefined },
        });

        expect(store.hasLoadedFullTree).toBe(true);
    });

    it('replace:false не помечает дерево полным', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true, replace: false });

        expect(store.hasLoadedFullTree).toBe(false);
    });

    it('при выключенном lazy-режиме флаг остаётся true и вывод его не ломает', async() => {
        // deferFoldersBootstrap:false → tree-state.js:10 даёт true с самого старта.
        const store = makeStore({ hasLoadedFullTree: true });

        await store.refreshFolders({ silent: true });

        expect(store.hasLoadedFullTree).toBe(true);
    });

    it('полная загрузка пересобирает loadedParentIds из hasChildren', async() => {
        const store = makeStore();

        await store.refreshFolders({ silent: true });

        // FULL_TREE: id=1 hasChildren, id=2 нет. Корень (0) всегда в наборе.
        expect([...store.loadedParentIds].sort()).toEqual([0, 1]);
    });

    // Парный к предыдущему: тот проверяет ДОБАВЛЕНИЕ (makeStore даёт чистый Set([0]),
    // мусора там нет по построению), этот — ОТБРАСЫВАНИЕ. Именно оно чистит id папок,
    // которых больше нет в дереве, — например после удаления папки, которую пользователь
    // раскрывал ([internal]).
    it('полная загрузка отбрасывает id, которых больше нет в folders', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 999]) });

        await store.refreshFolders({ silent: true });

        // 999 в FULL_TREE отсутствует — исчезает из набора.
        expect([...store.loadedParentIds].sort()).toEqual([0, 1]);
    });

    // Гонка: полный запрос стартовал первым, частичный вторым, ответы пришли в обратном
    // порядке. Устаревший ответ обязан быть отброшен целиком — и по данным, и по флагу,
    // и по loadedParentIds. Раньше пересборка Set жила вне requestId-guard
    // (loadCompleteFolderTree, после await) и затирала актуальное состояние.
    it('устаревший ответ не трогает ни флаг, ни loadedParentIds', async() => {
        const store = makeStore();
        const slowFull = deferred();

        Api.getFolders.mockImplementationOnce(() => slowFull.promise);
        const fullPromise = store.loadCompleteFolderTree({ silent: true });

        Api.getFolders.mockResolvedValueOnce({ folders: [{ id: 9, name: 'C', parentId: 0 }] });
        await store.loadFolderChildren(5);

        const afterPartialFlag = store.hasLoadedFullTree;
        const afterPartialIds = [...store.loadedParentIds].sort();

        slowFull.resolve({ folders: FULL_TREE });
        await fullPromise;

        expect(store.hasLoadedFullTree).toBe(afterPartialFlag);
        expect([...store.loadedParentIds].sort()).toEqual(afterPartialIds);
    });
});
