/**
 * [internal] ([internal]).
 *
 * `refreshFolders` гейтит два поля одного состояния разными условиями: флаг — по факту
 * запроса (`navigation.js:81`, `replace && isFullTreeRequest(params)`), а пересборку
 * `loadedParentIds` — по липкому `hasLoadedFullTree` (`:94`). Флаг в не-lazy режиме поднят
 * с бутстрапа (`tree-state.js:10`) и не сбрасывается ничем, поэтому усекающий запрос
 * пересобирает Set из ЧАСТИЧНОГО дерева и теряет валидные id.
 *
 * Отдельный файл, а не блок в navigation-full-tree-flag.test.js: там мок runtime задаёт
 * `deferFoldersBootstrap: true`, а этот дефект живёт в не-lazy режиме. `tree-state.js:3`
 * читает `getRuntime()` на уровне модуля, поэтому режим задаётся моком до импорта.
 *
 * `refreshFolders`, `navigation.js` и `tree-state.js` не мокаются намеренно (launch.md,
 * mocks policy): инвариант живёт в связке «params → гейт → Set».
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

// deferFoldersBootstrap: false — не-lazy режим (<=200 папок). Здесь tree-state.js:10 даёт
// hasLoadedFullTree = true сразу, без единого сетевого запроса.
jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(() => null),
    getPostType: jest.fn(() => 'attachment'),
    getRuntime: jest.fn(() => ({ trashFolderId: 999, deferFoldersBootstrap: false })),
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

/** Две ветки верхнего уровня, обе с детьми — обе валидно догружены пользователем. */
const TWO_BRANCHES = [
    { id: 1, name: 'A', parentId: 0, hasChildren: true },
    { id: 5, name: 'B', parentId: 0, hasChildren: true },
];

function makeStore(extraState = {})
{
    const base = mergeStore(navigationModule, treeStateModule);
    return Object.assign(Object.create(null), base, {
        folders: [...TWO_BRANCHES],
        openId: 0,
        isLoading: false,
        error: null,
        applyFolderFilter: jest.fn(),
        notify: jest.fn(),
        // Явно, а не из treeStateModule: поля вычисляются один раз при импорте, а Set
        // мутабелен и протёк бы между тестами.
        hasLoadedFullTree: true,
        loadedParentIds: new Set([0, 1, 5]),
        ...extraState,
    });
}

describe('refreshFolders — пересборка loadedParentIds гейтится фактом запроса ([internal])', () => {
    beforeEach(() => jest.clearAllMocks());

    // Ядро дефекта. Усекающий запрос (сервер отдаёт только запрошенную ветку) не должен
    // трогать Set: он описывает, что загружено целиком, а частичный ответ этого не меняет.
    it('усекающий params.parent_id при replace:true не теряет валидные id', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { parent_id: 1 } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    // Те же три усекающих параметра, что гейтят флаг (FolderReadController.php:94,107,120).
    it('усекающий params.search при replace:true не теряет валидные id', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { search: 'A' } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    it('усекающий params.ids при replace:true не теряет валидные id', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({ silent: true, params: { ids: [1] } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    // Контроль: полный запрос обязан пересобирать Set — иначе правка гейта сломала бы
    // то, ради чего пересборка существует.
    it('полный запрос по-прежнему пересобирает Set из свежих folders', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 5, 777]) });
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES });

        await store.refreshFolders({ silent: true });

        // 777 отброшен как устаревший, 1 и 5 восстановлены из hasChildren.
        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    // Контроль: частичная догрузка ходит с replace:false и Set не трогает — это поведение
    // было корректным и до правки, оно не должно измениться.
    it('частичная догрузка (replace:false) не трогает Set', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]] });

        await store.refreshFolders({
            silent: true,
            replace: false,
            params: { parent_id: 1 },
        });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });
});

describe('refreshFolders — data.fullTree читается напрямую, params-предикат только fallback ([internal])', () => {
    beforeEach(() => jest.clearAllMocks());

    // Сервер явно говорит fullTree:true, хотя params сам по себе НЕ полный (search задан) —
    // прямое чтение поля должно победить над params-реконструкцией.
    it('data.fullTree:true пересобирает Set, даже если params.search выглядит усекающим', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES, fullTree: true });

        await store.refreshFolders({ silent: true, params: { search: 'A' } });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    // Сервер явно говорит fullTree:false для запроса без params вообще (params сам по себе
    // выглядел бы как full по старому предикату) — прямое чтение поля должно не пересобирать.
    it('data.fullTree:false не пересобирает Set, даже если params выглядит полным', async() => {
        const store = makeStore();
        Api.getFolders.mockResolvedValue({ folders: [TWO_BRANCHES[0]], fullTree: false });

        await store.refreshFolders({ silent: true, params: {} });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });

    // Skew-safe fallback: сервер не прислал fullTree вообще (старый сервер до [internal]) —
    // поведение обязано остаться прежним, через isFullTreeRequest(params).
    it('fullTree отсутствует в ответе — fallback на isFullTreeRequest(params)', async() => {
        const store = makeStore({ loadedParentIds: new Set([0, 1, 5, 777]) });
        Api.getFolders.mockResolvedValue({ folders: TWO_BRANCHES });

        await store.refreshFolders({ silent: true });

        expect([...store.loadedParentIds].sort()).toEqual([0, 1, 5]);
    });
});
