/**
 * [internal] + [internal] + [internal]:
 * syncMediaToolbarTrashClass скрывает core `.delete-selected-button`
 * (не понимает Plathix Trash-таксономию) через нативный Backbone toolbar API
 * (`toolbar.get('deleteSelectedButton').$el.hide()`), не через CSS-класс-маркер.
 * Контроль-контракта: сломать hide-вызов → тест краснеет.
 */
import { syncMediaToolbarTrashClass } from '../trash-core-toolbar-suppress.js';

describe('trash-entry.js — syncMediaToolbarTrashClass ([internal], native toolbar API)', () => {
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

    it('в Trash-режиме скрывает deleteSelectedButton через $el.hide()', () => {
        syncMediaToolbarTrashClass({ openId: 99 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(1);
    });

    it('вне Trash-режима тоже скрывает deleteSelectedButton (симметричный баг, #269)', () => {
        syncMediaToolbarTrashClass({ openId: 5 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(1);
    });

    it('не падает, если .media-toolbar отсутствует в DOM', () => {
        document.body.innerHTML = '';

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('не падает, если wp.media.frame.content.get() не возвращает toolbar', () => {
        window.wp.media.frame.content.get = jest.fn(() => undefined);

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('не падает, если toolbar.get(\'deleteSelectedButton\') возвращает undefined', () => {
        window.wp.media.frame.content.get = jest.fn(() => ({ toolbar: { get: jest.fn(() => undefined) } }));

        expect(() => syncMediaToolbarTrashClass({ openId: 5 })).not.toThrow();
    });

    it('идемпотентно: повторный вызов не кидает ошибок (MutationObserver re-triggers)', () => {
        syncMediaToolbarTrashClass({ openId: 99 });
        syncMediaToolbarTrashClass({ openId: 99 });

        expect(deleteSelectedButton.$el.hide).toHaveBeenCalledTimes(2);
    });
});
