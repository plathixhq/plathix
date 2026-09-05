/**
 * Media grid in-memory cache — держит JS-side кэш ответов по папкам, чтобы возврат в
 * ранее посещённую папку был мгновенным. Инвалидируется при загрузке/удалении/перемещении
 * файлов (memClear / memInvalidateFolder), потребители — sidebar store и upload-события.
 *
 * NOTE ([internal]): Backbone-sync-патч через REST /plathix/v1/media-grid
 * (patchMediaGridSync) удалён как мёртвый — он никогда не вызывался, медиатека работает через
 * родной WP ajax_query_attachments. Здесь остаётся только in-memory кэш-слой.
 */

const MEM_TTL = 60_000; // 60s in-memory TTL
const _mem = new Map();

function memKey(params) {
    const sorted = {};
    for (const k of Object.keys(params).sort()) sorted[k] = params[k];
    return JSON.stringify(sorted);
}

function memGet(params) {
    const entry = _mem.get(memKey(params));
    if (!entry) return null;
    if (Date.now() - entry.ts > MEM_TTL) { _mem.delete(memKey(params)); return null; }
    return entry.data;
}

function memSet(params, data) {
    _mem.set(memKey(params), { data, ts: Date.now() });
}

export function memClear() {
    _mem.clear();
}

export function memInvalidateFolder(folderId) {
    const target = Number(folderId);
    for (const k of _mem.keys()) {
        try {
            const p = JSON.parse(k);
            if (p.folder_id === target || p.folder_id === -1 || p.folder_id === 0) {
                _mem.delete(k);
            }
        } catch {}
    }
}

