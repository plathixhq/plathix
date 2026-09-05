import { mergeStore } from '../../store/utils.js';
import { notificationsModule } from '../../store/notifications.js';

jest.mock('../../api.js', () => ({
    Api: {
        saveFavorites: jest.fn(),
    },
}));

jest.mock('../../runtime.js', () => ({
    getRuntime: () => ({ favorites: [] }),
}));

function makeStore(favoriteModule) {
    return Object.assign(Object.create(null), mergeStore(notificationsModule, favoriteModule), {
        notifications: [],
        _notifId: 0,
    });
}

// Макротаск-дренаж: цепочка .then().catch() внутри toggleFavorite глубже одного
// микротика, одиночный await Promise.resolve() её не доводит до конца.
const flushAsync = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * [internal] (skeptic follow-up на закрытый [internal], [internal]),
 * пересмотрен пакетом [internal] ([internal]): контракт «toggleFavorite() не вызывает
 * notify()» сужен до SUCCESS-пути — успешное сохранение по-прежнему тихое (эти тесты
 * остаются защитой от случайного success-toast). Провал Api.saveFavorites теперь
 * ОБЯЗАН давать откат оптимистичного состояния + ровно один error-toast; AbortError
 * (запрос вытеснен следующим, abort-guard в api.js) — тихий, без отката: его изменения
 * несёт следующий запрос полным массивом. Три предыдущие попытки headless Playwright
 * ловили этот же сценарий на неверном триггере (DOM/CSS-timing) — здесь чистая JS
 * store-логика, без браузера.
 *
 * favoritesModule.favorites/_favoritesSet are module-level state (shared array/Set
 * reference, not per-store data — mergeStore copies property DESCRIPTORS, not deep
 * clones) — each test calls jest.resetModules() and re-imports BOTH the module under
 * test and its jest.mock'd dependencies (Api), by the pattern already established in
 * media-frame-watcher.test.js: a mocked import cached at file top-level does not survive
 * resetModules(), it must be re-imported per test too.
 */
describe('favoritesModule — toggleFavorite', () => {
    beforeEach(() => {
        jest.resetModules();
    });

    it('adds a folder id to favorites and the lookup set', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);

        expect(store.favorites).toContain(10);
        expect(store.isFavorite(10)).toBe(true);
    });

    it('removes a folder id from favorites and the lookup set on second toggle', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        store.toggleFavorite(10);

        expect(store.favorites).not.toContain(10);
        expect(store.isFavorite(10)).toBe(false);
    });

    it('calls Api.saveFavorites with the updated favorites array', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);

        expect(Api.saveFavorites).toHaveBeenCalledWith(store.favorites);
    });

    it('does NOT create a notification when adding a favorite (regression contract, [internal])', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        await Promise.resolve();

        expect(store.notifications).toHaveLength(0);
    });

    it('does NOT create a notification when removing a favorite (regression contract, [internal])', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        store.toggleFavorite(10);
        await Promise.resolve();

        expect(store.notifications).toHaveLength(0);
    });

    it('rolls back the optimistic toggle and shows one error toast when save fails ([internal])', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        let rejectSave;
        Api.saveFavorites.mockReturnValue(new Promise((_resolve, reject) => { rejectSave = reject; }));
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        expect(store.isFavorite(10)).toBe(true);

        rejectSave(new Error('HTTP 500'));
        await flushAsync();

        expect(store.favorites).not.toContain(10);
        expect(store.isFavorite(10)).toBe(false);
        expect(store.notifications.filter((n) => n.type === 'error')).toHaveLength(1);
    });

    it('rolls back a removal-toggle to the last server-confirmed state when save fails ([internal])', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        await flushAsync();

        Api.saveFavorites.mockRejectedValue(new Error('HTTP 500'));
        store.toggleFavorite(10);
        await flushAsync();

        expect(store.favorites).toContain(10);
        expect(store.isFavorite(10)).toBe(true);
        expect(store.notifications.filter((n) => n.type === 'error')).toHaveLength(1);
    });

    it('stays silent and keeps optimistic state on AbortError (superseded request, [internal])', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        const abortError = new Error('The operation was aborted.');
        abortError.name = 'AbortError';
        Api.saveFavorites.mockRejectedValue(abortError);
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        await flushAsync();

        expect(store.favorites).toContain(10);
        expect(store.notifications).toHaveLength(0);
    });
});
