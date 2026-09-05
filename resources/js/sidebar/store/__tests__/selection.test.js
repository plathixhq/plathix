import { selectionModule } from '../selection.js';
import { uiStateModule } from '../ui-state.js';
import { mergeStore } from '../utils.js';

jest.mock('../../runtime.js', () => ({
    getMediaFrame: jest.fn(),
    getRuntime: () => ({ folders: [], deferFoldersBootstrap: false }),
}));

import { getMediaFrame } from '../../runtime.js';

function makeStore(extra = {}) {
    const base = mergeStore(uiStateModule, selectionModule);
    return Object.assign(Object.create(null), base, extra);
}

describe('selectionModule — media selection owner', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        document.body.innerHTML = '';
        getMediaFrame.mockReturnValue(null);
    });

    describe('getSelectedItemIds() — единый 3-source read', () => {
        it('объединяет DOM .selected, чекбоксы и wp.media frame в один Set по id', () => {
            document.body.innerHTML = `
                <div class="attachment selected" data-id="1"></div>
                <div class="attachment selected" data-id="2"></div>
                <input type="checkbox" name="media[]" value="3" checked>
            `;
            getMediaFrame.mockReturnValue({
                state: () => ({ get: (k) => (k === 'selection' ? { models: [{ id: 2 }, { id: 4 }] } : null) }),
            });
            const store = makeStore();
            // 1,2 из DOM + 3 из чекбокса + 2,4 из frame → Set схлопывает дубль 2
            expect(store.getSelectedItemIds().sort()).toEqual([1, 2, 3, 4]);
        });

        it('пустой выбор → []', () => {
            expect(makeStore().getSelectedItemIds()).toEqual([]);
        });
    });

    describe('ВХОД 1 — recountFromUi() (Math.max для интерактива)', () => {
        it('берёт большее из зеркал DOM и frame (не сумму — двойное зеркало)', () => {
            document.body.innerHTML = `
                <div class="attachment selected" data-id="1"></div>
                <div class="attachment selected" data-id="2"></div>
            `;
            // frame зеркалит те же 2 выбранных → Math.max(2,2)=2, НЕ сумма 4
            getMediaFrame.mockReturnValue({
                state: () => ({ get: (k) => (k === 'selection' ? { length: 2 } : null) }),
            });
            const store = makeStore();
            store.recountFromUi();
            expect(store.selectedMediaCount).toBe(2);
        });

        it('frame пуст, DOM держит выбор → берёт DOM', () => {
            document.body.innerHTML = `<div class="attachment selected" data-id="1"></div>`;
            const store = makeStore();
            store.recountFromUi();
            expect(store.selectedMediaCount).toBe(1);
        });
    });

    describe('ВХОД 2 — setFromMutationResult() (прямая установка после мутации)', () => {
        it('ставит счётчик = failedCount напрямую, НЕ пересчитывая из DOM', () => {
            const store = makeStore({ selectedMediaCount: 5 });
            store.setFromMutationResult(0);
            expect(store.selectedMediaCount).toBe(0);
        });

        it('частичный провал → счётчик = число не-обработанных', () => {
            const store = makeStore({ selectedMediaCount: 3 });
            store.setFromMutationResult(1);
            expect(store.selectedMediaCount).toBe(1);
        });
    });

    // Контроль-регресс: ДВА входа НЕ взаимозаменяемы. После optimistic-removal DOM пуст,
    // recountFromUi дал бы 0 даже при partial failed=1 — вот почему нужен отдельный вход.
    it('КОНТРОЛЬ: recountFromUi после removal даёт 0, setFromMutationResult(1) даёт 1 — входы различны', () => {
        document.body.innerHTML = ''; // DOM опустошён optimistic removal
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        store.recountFromUi();
        expect(store.selectedMediaCount).toBe(0); // пересчёт из UI = 0 (неверно для partial)

        store.setFromMutationResult(1);
        expect(store.selectedMediaCount).toBe(1); // прямая установка = верно
    });

    describe('clearSelectionDom()', () => {
        it('снимает .selected, чекбоксы и select-all; вызывает frame.reset', () => {
            document.body.innerHTML = `
                <div class="attachment selected" data-id="1"></div>
                <input type="checkbox" name="media[]" value="1" checked>
                <input type="checkbox" id="cb-select-all-1" checked>
            `;
            const reset = jest.fn();
            getMediaFrame.mockReturnValue({ state: () => ({ get: (k) => (k === 'selection' ? { reset } : null) }) });
            const store = makeStore();

            store.clearSelectionDom();

            expect(reset).toHaveBeenCalled();
            expect(document.querySelector('.attachment.selected')).toBeNull();
            expect(document.querySelector('input[name="media[]"]').checked).toBe(false);
            expect(document.querySelector('#cb-select-all-1').checked).toBe(false);
        });

        it('removeIds → физически удаляет узлы из DOM', () => {
            document.body.innerHTML = `
                <div class="attachment" data-id="7"></div>
                <tr id="post-7"></tr>
                <div class="attachment" data-id="8"></div>
            `;
            const store = makeStore();
            store.clearSelectionDom({ removeIds: [7] });
            expect(document.querySelector('.attachment[data-id="7"]')).toBeNull();
            expect(document.querySelector('tr#post-7')).toBeNull();
            expect(document.querySelector('.attachment[data-id="8"]')).not.toBeNull();
        });
    });
});
