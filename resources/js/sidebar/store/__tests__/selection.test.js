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

    describe('keeps store/selection state consistent across UI events', () => {
        it('mounts or dismisses the UI element under the expected conditions', () => {
            document.body.innerHTML = `
                <div class="attachment selected" data-id="1"></div>
                <div class="attachment selected" data-id="2"></div>
                <input type="checkbox" name="media[]" value="3" checked>
            `;
            getMediaFrame.mockReturnValue({
                state: () => ({ get: (k) => (k === 'selection' ? { models: [{ id: 2 }, { id: 4 }] } : null) }),
            });
            const store = makeStore();

            expect(store.getSelectedItemIds().sort()).toEqual([1, 2, 3, 4]);
        });

        it('keeps store/selection state consistent across UI events', () => {
            expect(makeStore().getSelectedItemIds()).toEqual([]);
        });
    });

    describe('keeps store/selection state consistent across UI events', () => {
        it('mounts or dismisses the UI element under the expected conditions', () => {
            document.body.innerHTML = `
                <div class="attachment selected" data-id="1"></div>
                <div class="attachment selected" data-id="2"></div>
            `;

            getMediaFrame.mockReturnValue({
                state: () => ({ get: (k) => (k === 'selection' ? { length: 2 } : null) }),
            });
            const store = makeStore();
            store.recountFromUi();
            expect(store.selectedMediaCount).toBe(2);
        });

        it('mounts or dismisses the UI element under the expected conditions', () => {
            document.body.innerHTML = `<div class="attachment selected" data-id="1"></div>`;
            const store = makeStore();
            store.recountFromUi();
            expect(store.selectedMediaCount).toBe(1);
        });
    });

    describe('keeps store/selection state consistent across UI events', () => {
        it('keeps store/selection state consistent across UI events', () => {
            const store = makeStore({ selectedMediaCount: 5 });
            store.setFromMutationResult(0);
            expect(store.selectedMediaCount).toBe(0);
        });

        it('keeps store/selection state consistent across UI events', () => {
            const store = makeStore({ selectedMediaCount: 3 });
            store.setFromMutationResult(1);
            expect(store.selectedMediaCount).toBe(1);
        });
    });



    it('keeps store/selection state consistent across UI events', () => {
        document.body.innerHTML = '';
        getMediaFrame.mockReturnValue(null);
        const store = makeStore();

        store.recountFromUi();
        expect(store.selectedMediaCount).toBe(0);

        store.setFromMutationResult(1);
        expect(store.selectedMediaCount).toBe(1);
    });

    describe('clearSelectionDom()', () => {
        it('mounts or dismisses the UI element under the expected conditions', () => {
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

        it('gates the bulk/drag-and-drop action behind the expected confirmation', () => {
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
