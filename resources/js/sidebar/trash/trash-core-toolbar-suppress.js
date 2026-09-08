

export function syncMediaToolbarTrashClass(store) {
    const toolbar = document.querySelector('.media-toolbar');
    if (!toolbar) {
        return;
    }
    const contentView = /** @type {{ toolbar?: { get?: (id: string) => { $el?: { hide?: () => void } } } } | undefined} */ (
        window.wp?.media?.frame?.content?.get?.()
    );
    const deleteSelectedButton = contentView?.toolbar?.get?.('deleteSelectedButton');
    deleteSelectedButton?.$el?.hide?.();
}
