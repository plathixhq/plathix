import Alpine from 'alpinejs';
import { Events } from './events.js';
import { getStateValue, hasStateFlag, setStateFlag, setStateValue } from './state.js';
import { t } from './i18n.js';








export function enableFolderReorder() {
    if (hasStateFlag('folderReorderBound')) {
        return;
    }

    const resolveSiblingDropPosition = (store, folderId, pos) => {
        const folder = store.folders?.find((f) => Number(f.id) === folderId);
        if (!folder) {
            return null;
        }

        const targetParentId = Number(folder.parentId || 0);
        const siblings = (store.folders || [])
            .filter((f) => Number(f.parentId || 0) === targetParentId && !f.isProtected)
            .sort((a, b) => Number(a.position || 0) - Number(b.position || 0) || String(a.name).localeCompare(String(b.name)));
        const idx = siblings.findIndex((f) => Number(f.id) === folderId);
        const folderPos = Number(folder.position) || (idx + 1) * 1000;
        let newPosition;
        if (pos === 'before') {
            const prev = siblings[idx - 1];
            const prevPos = prev ? (Number(prev.position) || idx * 1000) : 0;
            newPosition = idx === 0 ? folderPos - 500 : Math.round((prevPos + folderPos) / 2);
        } else {
            const next = siblings[idx + 1];
            const nextPos = next ? (Number(next.position) || (idx + 2) * 1000) : folderPos + 2000;
            newPosition = Math.round((folderPos + nextPos) / 2);
        }

        return {
            targetParentId,
            newPosition: Math.max(1, newPosition),
        };
    };

    let folderDropTargetEl = null;
    let folderDropPos = null;

    const clearFolderDropTarget = () => {
        if (folderDropTargetEl) {
            folderDropTargetEl.classList.remove('folder-drag-inside', 'folder-drag-before', 'folder-drag-after');
            folderDropTargetEl = null;
        }
        folderDropPos = null;
    };

    window.addEventListener('dragenter', (event) => {
        if (!Alpine.store('plathix')?.isFolderDragging) return;
        event.stopImmediatePropagation();
    }, true);

    window.addEventListener('dragover', (event) => {
        if (!Alpine.store('plathix')?.isFolderDragging) return;
        event.stopImmediatePropagation();

        const store = Alpine.store('plathix');
        const draggedId = Number(store.folderDragId);
        const folderEl = event.target?.closest?.('.plathix-folder[data-folder-id]');
        const folderId = folderEl ? Number(folderEl.dataset.folderId) : null;

        if (!folderEl || !folderId || folderId === draggedId) {
            clearFolderDropTarget();
            return;
        }

        const folder = store.folders?.find((f) => Number(f.id) === folderId);
        if (folder?.isProtected) {
            clearFolderDropTarget();
            return;
        }

        event.preventDefault();

        const rect = folderEl.getBoundingClientRect();
        const y = event.clientY - rect.top;
        const h = rect.height;
        const newPos = y < h * 0.3 ? 'before' : y > h * 0.7 ? 'after' : 'inside';

        if (folderDropTargetEl !== folderEl || folderDropPos !== newPos) {
            clearFolderDropTarget();
            folderDropTargetEl = folderEl;
            folderDropPos = newPos;
            folderEl.classList.add(`folder-drag-${newPos}`);
        }
    }, true);

    window.addEventListener('dragleave', (event) => {
        if (!Alpine.store('plathix')?.isFolderDragging) return;
        event.stopImmediatePropagation();
        if (folderDropTargetEl && !folderDropTargetEl.contains(event.relatedTarget)) {
            clearFolderDropTarget();
        }
    }, true);

    window.addEventListener('drop', (event) => {
        if (!Alpine.store('plathix')?.isFolderDragging) return;
        event.stopImmediatePropagation();
        event.preventDefault();

        const store = Alpine.store('plathix');
        const draggedId = Number(store.folderDragId);
        const folderId = folderDropTargetEl ? Number(folderDropTargetEl.dataset.folderId) : null;
        const pos = folderDropPos;

        clearFolderDropTarget();
        store.isFolderDragging = false;
        store.folderDragId = null;
        document.body.classList.remove('plathix-internal-drag');

        if (!folderId || !draggedId || draggedId === folderId) return;

        const folder = store.folders?.find((f) => Number(f.id) === folderId);
        if (!folder || folder.isProtected) return;

        if (pos === 'inside') {
            store.moveFolderToParent(draggedId, folderId);
        } else {
            const siblingDrop = resolveSiblingDropPosition(store, folderId, pos);
            if (!siblingDrop || siblingDrop.targetParentId === draggedId) return;
            store.moveFolderToSiblingOf(draggedId, siblingDrop.targetParentId, siblingDrop.newPosition);
        }
    }, true);

    window.addEventListener('dragend', () => {
        const store = Alpine.store('plathix');
        if (store?.isFolderDragging) {
            clearFolderDropTarget();
            store.isFolderDragging = false;
            store.folderDragId = null;
            document.body.classList.remove('plathix-internal-drag');
        }
    }, true);

    setStateFlag('folderReorderBound');
}



export function confirmAndMoveItems(itemIds, targetFolderId, folderEl) {
    const plathixStore = Alpine.store('plathix');








    const trashFolderId = Number(window.Plathix?.trashFolderId || 0);
    const dragSourceIsTrash = plathixStore.isCurrentFolderTrashed()
        || (trashFolderId > 0 && Number(plathixStore.openId) === trashFolderId);

    if (dragSourceIsTrash) {
        const folderName = folderEl?.querySelector('.plathix-folder__name')?.textContent?.trim() || '';




        const msg = folderName
            ? t('dragdrop_restore_confirm_named', 'Restore file and move it to folder "%s"?').replace('%s', folderName)
            : t('dragdrop_restore_confirm', 'Restore file and move it to this folder?');
        if (!window.confirm(msg)) return;
    } else if (plathixStore.bulkSafeMode && itemIds.length >= 10) {
        const msg = itemIds.length + ' ' + t('bulk_confirm_move', 'items will be moved. Continue?');
        if (!window.confirm(msg)) return;
    }
    plathixStore.moveItemsBulk(itemIds, targetFolderId);
}



export function enableAttachmentDrop() {
    if (hasStateFlag('attachmentDropBound')) {
        return;
    }

    const markDraggable = () => {
        document.querySelectorAll('.attachment[data-id], tr[id^="post-"]').forEach((el) => {
            if (!el.hasAttribute('draggable')) {
                el.setAttribute('draggable', 'true');
            }
        });
    };

    markDraggable();
    if (!getStateValue('attachmentDnDObserver')) {
        const observer = new MutationObserver(() => markDraggable());
        observer.observe(document.body, { subtree: true, childList: true });
        setStateValue('attachmentDnDObserver', observer);
    }

    const resolveFallbackId = (target) => {
        const attachment = target?.closest?.('.attachment[data-id]');
        if (attachment?.dataset?.id) {
            return Number(attachment.dataset.id);
        }

        const row = target?.closest?.('tr[id^="post-"]');
        if (row?.id?.startsWith('post-')) {
            return Number(row.id.replace('post-', '')) || 0;
        }

        return 0;
    };

    const parseDraggedItemIds = (event) => {
        const raw = event.dataTransfer?.getData(Events.DRAG_ITEMS_MIME) || '[]';
        try {
            const itemIds = JSON.parse(raw);
            return Array.isArray(itemIds) && itemIds.length ? itemIds : [];
        } catch (e) {
            return [];
        }
    };

    let internalDragActive = false;
    let dragOverFolder = null;

    const clearDragOver = () => {
        if (dragOverFolder) {
            dragOverFolder.classList.remove('is-drag-over');
            dragOverFolder = null;
        }
    };

    window.addEventListener('dragover', (event) => {
        if (!internalDragActive) return;
        event.stopImmediatePropagation();

        const folderEl = event.target?.closest?.('.plathix-folder[data-folder-id]');
        if (folderEl !== dragOverFolder) {
            clearDragOver();
            if (folderEl) {
                dragOverFolder = folderEl;
                folderEl.classList.add('is-drag-over');
            }
        }
        if (folderEl) {
            event.preventDefault();
        }
    }, true);

    window.addEventListener('dragenter', (event) => {
        if (!internalDragActive) return;
        event.stopImmediatePropagation();
    }, true);

    window.addEventListener('dragleave', (event) => {
        if (!internalDragActive) return;
        event.stopImmediatePropagation();
    }, true);

    window.addEventListener('drop', (event) => {
        if (!internalDragActive) return;
        event.stopImmediatePropagation();
        event.preventDefault();

        const folderEl = event.target?.closest?.('.plathix-folder[data-folder-id]');
        clearDragOver();

        if (!folderEl) return;
        const targetFolderId = Number(folderEl.dataset.folderId);
        if (!(targetFolderId > 0)) return;

        const itemIds = parseDraggedItemIds(event);
        if (!itemIds.length) return;

        confirmAndMoveItems(itemIds, targetFolderId, folderEl);
    }, true);

    document.addEventListener('dragstart', (event) => {
        if (!event.dataTransfer) {
            return;
        }

        if (event.target?.closest?.('.plathix-folder[data-folder-id]')) {
            return;
        }

        const store = Alpine.store('plathix');
        if (!store?.canAssign) {
            return;
        }

        const ids = store.getSelectedItemIds();
        const fallbackId = resolveFallbackId(event.target);
        const payload = ids.length ? ids : fallbackId > 0 ? [fallbackId] : [];
        if (!payload.length) {
            return;
        }

        internalDragActive = true;
        document.body.classList.add('plathix-internal-drag');
        const encoded = JSON.stringify(payload);
        event.dataTransfer.setData(Events.DRAG_ITEMS_MIME, encoded);
        event.dataTransfer.effectAllowed = 'move';
    }, true);

    document.addEventListener('dragend', () => {
        internalDragActive = false;
        document.body.classList.remove('plathix-internal-drag');
        clearDragOver();
    }, true);

    setStateFlag('attachmentDropBound');
}



export function enableAttachmentDnD() {
    enableFolderReorder();
    enableAttachmentDrop();
}
