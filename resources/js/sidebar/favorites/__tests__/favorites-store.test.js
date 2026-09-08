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



const flushAsync = () => new Promise((resolve) => setTimeout(resolve, 0));



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

    it('covers public behavior without internal references', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        await Promise.resolve();

        expect(store.notifications).toHaveLength(0);
    });

    it('covers public behavior without internal references', async () => {
        const { favoritesModule } = await import('../favorites-store.js');
        const { Api } = await import('../../api.js');
        Api.saveFavorites.mockResolvedValue({});
        const store = makeStore(favoritesModule);

        store.toggleFavorite(10);
        store.toggleFavorite(10);
        await Promise.resolve();

        expect(store.notifications).toHaveLength(0);
    });

    it('covers public behavior without internal references', async () => {
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

    it('covers public behavior without internal references', async () => {
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

    it('covers public behavior without internal references', async () => {
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
