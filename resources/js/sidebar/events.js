/**
 * CustomEvent / dataTransfer MIME constants.
 *
 * DOM events are dispatched on `window` via `new CustomEvent(Events.X)`.
 * MIME constants are used as `dataTransfer.setData(Events.X, payload)` keys.
 *
 * Payloads:
 *  FOLDER_CREATED   — no detail ([internal]: emitted, no current window listener —
 *                      dispatch itself is a tested contract, see folders-crud.test.js
 *                      [internal]; reserved for future cross-module reactivity)
 *  FOLDER_DELETED   — no detail
 *  FOLDER_MOVED     — no detail ([internal]: same as FOLDER_CREATED — emitted from
 *                      folders-crud.js and folder-move.js, no current window listener)
 *  CONTEXT_MENU     — CustomEvent detail: { folder: WPCFolder, event: PointerEvent }
 *  DRAG_ITEMS_MIME  — dataTransfer JSON: number[]  (attachment post IDs)
 *  DRAG_FOLDER_MIME — dataTransfer key for folder drag (FolderTree.js)
 */
export const Events = Object.freeze({
    FOLDER_CREATED: 'plathix:folder-created',
    FOLDER_DELETED: 'plathix:folder-deleted',
    FOLDER_MOVED: 'plathix:folder-moved',
    CONTEXT_MENU: 'folder-contextmenu',
    DRAG_ITEMS_MIME: 'application/x-plathix-item',
    DRAG_FOLDER_MIME: 'application/x-plathix-folder',
    TOOLBAR_ACTION:  'plathix:toolbar-action',
});
