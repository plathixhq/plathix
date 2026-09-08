




export function isInDeletedSubtree(folders, deletedSet) {
    const byId = new Map(folders.map((f) => [Number(f.id), f]));
    return (fId) => {
        let cur = Number(fId);
        while (cur > 0) {
            if (deletedSet.has(cur)) return true;
            cur = Number(byId.get(cur)?.parentId || 0);
        }
        return false;
    };
}



export function findReattachTarget(folders, trashFolderId) {
    return folders.find(
        (f) => f.isProtected && Number(f.id) > 0 && Number(f.id) !== Number(trashFolderId)
    );
}



export function collectAncestorIds(folders, folderId) {
    const byId = new Map(folders.map((f) => [Number(f.id), f]));
    const ancestors = [];
    const seen = new Set();
    let cur = Number(byId.get(Number(folderId))?.parentId || 0);
    while (cur > 0 && !seen.has(cur)) {
        seen.add(cur);
        ancestors.push(cur);
        cur = Number(byId.get(cur)?.parentId || 0);
    }

    return ancestors.reverse();
}



export function buildFolderTreeIndex(folders) {
    const byId = new Map(folders.map((f) => [Number(f.id), f]));
    const byParent = new Map();

    const isAncestorCycle = (folder) => {
        const seen = new Set([Number(folder.id)]);
        let cur = Number(folder.parentId || 0);
        while (cur > 0) {
            if (seen.has(cur)) {
                return true;
            }
            seen.add(cur);
            cur = Number(byId.get(cur)?.parentId || 0);
        }
        return false;
    };

    folders.forEach((folder) => {
        const parentId = isAncestorCycle(folder) ? 0 : Number(folder.parentId || 0);
        const group = byParent.get(parentId) || [];
        group.push(folder);
        byParent.set(parentId, group);
    });

    byParent.forEach((group) => {
        group.sort((left, right) => {
            const leftPos = Number(left?.position || 0);
            const rightPos = Number(right?.position || 0);
            if (leftPos !== rightPos) {
                return leftPos - rightPos;
            }
            return String(left?.name || '').localeCompare(String(right?.name || ''), undefined, { sensitivity: 'base' });
        });
    });

    return byParent;
}
