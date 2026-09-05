import { isInDeletedSubtree, findReattachTarget, collectAncestorIds } from '../folder-tree-utils.js';

describe('isInDeletedSubtree', () => {
    const folders = [
        { id: 10, parentId: 0 },
        { id: 12, parentId: 10 },
        { id: 20, parentId: 0 },
    ];

    it('matches the deleted folder itself', () => {
        const pred = isInDeletedSubtree(folders, new Set([10]));
        expect(pred(10)).toBe(true);
    });

    it('matches a descendant of a deleted folder', () => {
        const pred = isInDeletedSubtree(folders, new Set([10]));
        expect(pred(12)).toBe(true);
    });

    it('does not match an unrelated folder', () => {
        const pred = isInDeletedSubtree(folders, new Set([10]));
        expect(pred(20)).toBe(false);
    });

    it('openId=0 returns false (no current folder)', () => {
        const pred = isInDeletedSubtree(folders, new Set([10]));
        expect(pred(0)).toBe(false);
    });

    it('works with a multi-id set (bulk)', () => {
        const pred = isInDeletedSubtree(folders, new Set([10, 20]));
        expect(pred(12)).toBe(true);   // descendant of 10
        expect(pred(20)).toBe(true);   // self
    });
});

describe('findReattachTarget', () => {
    it('returns first protected folder, skipping trash', () => {
        const folders = [
            { id: 77, isProtected: true },   // trash
            { id: 5, isProtected: true },    // uncategorized
            { id: 10, isProtected: false },
        ];
        expect(findReattachTarget(folders, 77)?.id).toBe(5);
    });

    it('returns undefined when no protected non-trash folder remains', () => {
        const folders = [
            { id: 77, isProtected: true },   // trash
            { id: 10, isProtected: false },
        ];
        expect(findReattachTarget(folders, 77)).toBeUndefined();
    });
});

describe('collectAncestorIds', () => {
    const folders = [
        { id: 10, parentId: 0 },   // корень
        { id: 12, parentId: 10 },  // вложенная
        { id: 15, parentId: 12 },  // целевая
        { id: 20, parentId: 0 },   // несвязанный корень
    ];

    it('returns ancestors ordered from root to folder (root first)', () => {
        // предки 15 → [10, 12] (10 ближе к корню, идёт первым для deferred-догрузки)
        expect(collectAncestorIds(folders, 15)).toEqual([10, 12]);
    });

    it('returns empty array for a root folder (no ancestors)', () => {
        expect(collectAncestorIds(folders, 10)).toEqual([]);
    });

    it('excludes the folder itself', () => {
        expect(collectAncestorIds(folders, 12)).toEqual([10]);
        expect(collectAncestorIds(folders, 12)).not.toContain(12);
    });

    it('normalizes string ids', () => {
        expect(collectAncestorIds(folders, '15')).toEqual([10, 12]);
    });

    it('breaks on a cycle without infinite loop', () => {
        const cyclic = [
            { id: 1, parentId: 2 },
            { id: 2, parentId: 1 },
        ];
        // не зацикливается; возвращает конечный набор посещённых предков
        const result = collectAncestorIds(cyclic, 1);
        expect(result.length).toBeLessThanOrEqual(2);
    });

    it('stops at a dangling parentId (parent not in folders)', () => {
        const dangling = [{ id: 5, parentId: 999 }];
        // 999 не найден в folders → обход обрывается на нём (parentId=0)
        expect(collectAncestorIds(dangling, 5)).toEqual([999]);
    });
});
