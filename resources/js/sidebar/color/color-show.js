const HEX_RE = /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/;



export function safeHexColor(color) {
    return typeof color === 'string' && HEX_RE.test(color) ? color : null;
}


export const colorShowImpl = {
    folderColorStyle(folder) {
        const c = safeHexColor(folder?.color);
        return c ? 'color:' + c : '';
    },
    folderColorFill(folder) {
        const c = safeHexColor(folder?.color);
        return c ? c + '33' : 'none';
    },
};
