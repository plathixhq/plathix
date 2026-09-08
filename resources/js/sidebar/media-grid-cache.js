


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

