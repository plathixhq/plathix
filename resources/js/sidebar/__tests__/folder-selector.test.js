jest.mock('../api.js', () => ({
    Api: {
        getFolders: jest.fn(),
    },
}));

import { Api } from '../api.js';
import { createFolderSelector } from '../folder-selector.js';

describe('folder selector component', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="target"></div>';
        jest.clearAllMocks();
    });

    it('renders flattened tree options and returns selected value', async () => {
        Api.getFolders.mockResolvedValue({
            folders: [
                { id: 1, parentId: 0, name: 'Root', position: 1, isProtected: false },
                { id: 2, parentId: 1, name: 'Child', position: 1, isProtected: false },
                { id: 3, parentId: 0, name: 'All Files', position: 0, isProtected: true },
            ],
        });

        const onChange = jest.fn();
        const selector = await createFolderSelector('#target', {
            includeAll: true,
            allLabel: 'Everything',
            includeProtected: false,
            value: 2,
            onChange,
        });

        const select = document.querySelector('#target select');
        const options = Array.from(select.options).map((option) => option.textContent);

        expect(Api.getFolders).toHaveBeenCalledWith({});
        expect(options).toEqual(['Everything', 'Root', '  ↳ Child']);
        expect(selector.getValue()).toBe(2);

        select.value = '1';
        select.dispatchEvent(new Event('change'));

        expect(onChange).toHaveBeenCalledWith(1, selector);
        expect(selector.getValue()).toBe(1);
    });

    it('supports placeholder and refresh', async () => {
        Api.getFolders
            .mockResolvedValueOnce({
                folders: [{ id: 5, parentId: 0, name: 'Alpha', position: 1, isProtected: false }],
            })
            .mockResolvedValueOnce({
                folders: [{ id: 7, parentId: 0, name: 'Beta', position: 1, isProtected: false }],
            });

        const selector = await createFolderSelector(document.getElementById('target'), {
            placeholder: 'Select folder',
        });

        let options = Array.from(document.querySelectorAll('#target option')).map((option) => option.textContent);
        expect(options).toEqual(['Select folder', 'Alpha']);
        expect(selector.getValue()).toBe(0);

        await selector.refresh();

        options = Array.from(document.querySelectorAll('#target option')).map((option) => option.textContent);
        expect(options).toEqual(['Select folder', 'Beta']);
    });
});

