const TTL = 45_000;
const _store = new Map();



let _epoch = 0;

function key(params) {
    const sorted = {};
    for (const k of Object.keys(params).sort()) sorted[k] = params[k];
    return JSON.stringify(sorted);
}

export function cacheEpoch() {
    return _epoch;
}

export function cacheGet(params) {
    const entry = _store.get(key(params));
    if (!entry) return null;
    if (Date.now() - entry.ts > TTL) { _store.delete(key(params)); return null; }
    return { data: entry.data, epoch: entry.epoch };
}

export function cacheSet(params, data, epoch) {
    if (epoch !== _epoch) return;
    _store.set(key(params), { data, ts: Date.now(), epoch });
}

export function cacheClear() {
    _store.clear();
    _epoch++;
}

/**
 * Invalidates entries that match the given folder_id, plus entries
 * with folder_id=0 (which show all items and are now stale too).
 * Use after upload/delete/move to a specific folder.
 */
export function cacheInvalidateFolder(folderId) {
    const target = Number(folderId);
    _epoch++;
    for (const k of _store.keys()) {
        try {
            const p = JSON.parse(k);
            if (p.folder_id === target || p.folder_id === 0) _store.delete(k);
        } catch {}
    }
}

/**
 * Invalidates all entries for a given screen_base (e.g. 'upload' or 'edit').
 * Use when the mutation affects the whole screen but folder is unknown.
 */
export function cacheInvalidateScreen(screenBase) {
    _epoch++;
    for (const k of _store.keys()) {
        try {
            const p = JSON.parse(k);
            if (p.screen_base === screenBase) _store.delete(k);
        } catch {}
    }
}



function onFoldersChanged(payload) {
    const ids = Array.isArray(payload?.folderIds) ? payload.folderIds : [];
    for (const raw of ids) {
        const id = Number(raw);
        if (Number.isFinite(id) && id >= 0) cacheInvalidateFolder(id);
    }
}



export function bindFolderMutationSlot() {
    window.wp?.hooks?.addAction?.('plathix.foldersChanged', 'plathix/static-list-cache', onFoldersChanged);
}
