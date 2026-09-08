

import { syncMediaToolbarTrashClass } from '../trash-core-toolbar-suppress.js';

describe('handles trash workflow consistently', () => {
    let deleteSelectedButton;

    beforeEach(() => {
        document.body.innerHTML = '<div class="media-toolbar"></div>';
        window.Plathix = { trashFolderId: 99 };
        deleteSelectedButton = { $el: { hide: jest.fn(), show: jest.fn() } };
        window.wp = {
            media: {
                frame: {
                    content: {
                        get: jest.fn(() => ({
                            toolbar: {
                                get: jest.fn((id) => (id === 'deleteSelectedButton' ? deleteSelectedButton : undefined)),
                            },
                        })),
                    },
                },
            },
        };
    });

    afterEach(() => {
        delete window.Plathix;
        delete window.wp;
    });

    it('handles trash workflow consistently', () => {
        syncMediaToolbarTrashClass({ openId: 99 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(1);
    });

    it('handles trash workflow consistently', () => {
        syncMediaToolbarTrashClass({ openId: 5 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(1);
    });

    it('covers public behavior without internal references', () => {
        document.body.innerHTML = '';

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('covers public behavior without internal references', () => {
        window.wp.media.frame.content.get = jest.fn(() => undefined);

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('handles trash workflow consistently', () => {
        window.wp.media.frame.content.get = jest.fn(() => ({ toolbar: { get: jest.fn(() => undefined) } }));

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('covers public behavior without internal references', () => {
        syncMediaToolbarTrashClass({ openId: 99 });
        syncMediaToolbarTrashClass({ openId: 99 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(2);
    });
});
