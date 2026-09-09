

import { uiStateModule } from '../ui-state.js';

describe('handles trash workflow consistently', () => {
    it('handles trash workflow consistently', () => {
        const store = Object.assign(Object.create(uiStateModule), { _trashImpl: null });

        expect(store.isCurrentFolderTrashed()).toBe(false);
    });

    it('handles trash workflow consistently', () => {
        const impl = { isCurrentFolderTrashed: jest.fn(() => true) };
        const store = Object.assign(Object.create(uiStateModule), { _trashImpl: impl });

        expect(store.isCurrentFolderTrashed()).toBe(true);
        expect(impl.isCurrentFolderTrashed).toHaveBeenCalledTimes(1);
    });
});



describe('uiStateModule.withLoading', () => {
    function makeStore() {
        return Object.assign(Object.create(uiStateModule), { isLoading: false, error: null });
    }

    it('keeps store/selection state consistent across UI events', async() => {
        const store = makeStore();

        await expect(store.withLoading(async() => {
            throw new Error('boom');
        })).resolves.toBeUndefined();

        expect(store.error).toBe('boom');
        expect(store.isLoading).toBe(false);
    });

    it('keeps store/selection state consistent across UI events', async() => {
        const store = makeStore();

        await expect(store.withLoading(async() => {
            throw new Error('boom');
        }, { rethrow: true })).rejects.toThrow('boom');

        expect(store.error).toBe('boom');
        expect(store.isLoading).toBe(false);
    });

    it('keeps store/selection state consistent across UI events', async() => {
        const store = makeStore();

        const result = await store.withLoading(async() => 'ok');

        expect(result).toBe('ok');
        expect(store.isLoading).toBe(false);
        expect(store.error).toBe(null);
    });
});
