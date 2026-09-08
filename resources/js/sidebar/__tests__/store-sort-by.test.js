jest.mock('../runtime.js', () => ({
    getPostType: () => 'attachment',
    getRuntime: () => ({}),
}));

describe('sidebarStore sortBy persistence', () => {
    beforeEach(() => {
        localStorage.clear();
        jest.resetModules();
    });

    it('setSortBy persists the chosen value to localStorage under a post-type-scoped key', async () => {
        const { sidebarStore } = await import('../store.js');

        sidebarStore.setSortBy('alpha');

        expect(localStorage.getItem('plathix_sort_by_attachment')).toBe('alpha');
        expect(sidebarStore.sortBy).toBe('alpha');
    });

    it('reinitializing the module reads a previously stored valid value instead of defaulting', async () => {
        localStorage.setItem('plathix_sort_by_attachment', 'new');

        const { sidebarStore } = await import('../store.js');

        expect(sidebarStore.sortBy).toBe('new');
    });

    it('an invalid stored value falls back to default instead of breaking initialization', async () => {
        localStorage.setItem('plathix_sort_by_attachment', 'garbage');

        const { sidebarStore } = await import('../store.js');

        expect(sidebarStore.sortBy).toBe('default');
    });
});
