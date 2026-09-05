/**
 * Чистые утилиты дерева папок ([internal]). Извлечены из дублей в
 * folders-crud.deleteFolder и bulk-delete.confirmDeleteSelectedFolders.
 * Без this/store — работают над массивом folders.
 */

/**
 * Каррированный предикат: попадает ли папка fId в удаляемое поддерево.
 * Обход вверх по parentId до корня; true, если встретили любую папку из deletedSet.
 *
 * @param {Array<{id:number|string, parentId?:number|string}>} folders
 * @param {Set<number>} deletedSet  множество id удаляемых папок (для single — new Set([id]))
 * @returns {(fId: number|string) => boolean}
 */
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

/**
 * Куда перейти после удаления текущей папки: первая защищённая папка (id>0), кроме корзины.
 *
 * @param {Array<{id:number|string, isProtected?:boolean}>} folders
 * @param {number} trashFolderId
 * @returns {object|undefined}
 */
export function findReattachTarget(folders, trashFolderId) {
    return folders.find(
        (f) => f.isProtected && Number(f.id) > 0 && Number(f.id) !== Number(trashFolderId)
    );
}

/**
 * Собрать id всех предков папки (обход вверх по parentId).
 *
 * Возвращает id предков БЕЗ самой папки, упорядоченные от корня к папке
 * (ближайший к корню — первым). Порядок важен для deferred-tree догрузки:
 * родителя нужно догрузить раньше ребёнка, иначе parentId следующего предка
 * ещё не разрешается в this.folders ([internal]).
 *
 * Защита от циклов: Set посещённых id, обрыв при повторе. Несуществующий
 * parentId обрывает обход (cur становится 0).
 *
 * @param {Array<{id:number|string, parentId?:number|string}>} folders
 * @param {number|string} folderId
 * @returns {number[]} id предков, от корня к папке
 */
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
    // обход шёл от папки к корню — развернуть в порядок «от корня к папке»
    return ancestors.reverse();
}
