/**
 * [internal]: isCurrentFolderTrashed() — stub/impl паттерн (см. _colorImpl рядом).
 * Без домёрженного _trashImpl (модуль trash/ не загружен) должен безопасно вернуть
 * false — существующее поведение (openId===trashFolderId) остаётся единственным
 * триггером «Restore», ничего не ломается при graceful degradation.
 */
import { uiStateModule } from '../ui-state.js';

describe('uiStateModule.isCurrentFolderTrashed ([internal])', () => {
    it('возвращает false, если _trashImpl не домёрген (trash-модуль не загружен)', () => {
        const store = Object.assign(Object.create(uiStateModule), { _trashImpl: null });

        expect(store.isCurrentFolderTrashed()).toBe(false);
    });

    it('делегирует в _trashImpl.isCurrentFolderTrashed(), когда он домёрген', () => {
        const impl = { isCurrentFolderTrashed: jest.fn(() => true) };
        const store = Object.assign(Object.create(uiStateModule), { _trashImpl: impl });

        expect(store.isCurrentFolderTrashed()).toBe(true);
        expect(impl.isCurrentFolderTrashed).toHaveBeenCalledTimes(1);
    });
});

/**
 * [internal]: withLoading глотал ошибку безусловно — недостижимый rollback в
 * color-edit.js. Опциональный { rethrow: true } делает ошибку доступной вызывающему,
 * не меняя поведение по умолчанию (используется остальными 6 call-site'ами модуля).
 */
describe('uiStateModule.withLoading', () => {
    function makeStore() {
        return Object.assign(Object.create(uiStateModule), { isLoading: false, error: null });
    }

    it('без rethrow: глотает ошибку, резолвится, выставляет error, сбрасывает isLoading', async() => {
        const store = makeStore();

        await expect(store.withLoading(async() => {
            throw new Error('boom');
        })).resolves.toBeUndefined();

        expect(store.error).toBe('boom');
        expect(store.isLoading).toBe(false);
    });

    it('с rethrow: true: пробрасывает ошибку, но всё равно выставляет error и сбрасывает isLoading', async() => {
        const store = makeStore();

        await expect(store.withLoading(async() => {
            throw new Error('boom');
        }, { rethrow: true })).rejects.toThrow('boom');

        expect(store.error).toBe('boom');
        expect(store.isLoading).toBe(false);
    });

    it('без ошибки: возвращает результат fn, isLoading сброшен, error не тронут', async() => {
        const store = makeStore();

        const result = await store.withLoading(async() => 'ok');

        expect(result).toBe('ok');
        expect(store.isLoading).toBe(false);
        expect(store.error).toBe(null);
    });
});
