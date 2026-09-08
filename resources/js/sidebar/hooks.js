export function doAction(name, payload) {
    try {
        window.wp?.hooks?.doAction?.(name, payload);
    } catch {}
}

export function applyFilters(name, value, payload) {
    try {
        const fn = window.wp?.hooks?.applyFilters;
        if (typeof fn === 'function') {
            return fn(name, value, payload);
        }
    } catch {}
    return value;
}
