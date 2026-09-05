import { mergeStore } from '../utils.js';

describe('mergeStore', () => {
    it('copies plain properties from all sources', () => {
        const a = { x: 1 };
        const b = { y: 2 };
        const result = mergeStore(a, b);
        expect(result.x).toBe(1);
        expect(result.y).toBe(2);
    });

    it('preserves getter descriptors (does not evaluate them at merge time)', () => {
        let calls = 0;
        const src = {
            get computed() {
                calls++;
                return 42;
            },
        };
        const result = mergeStore(src);
        expect(calls).toBe(0);
        expect(result.computed).toBe(42);
        expect(calls).toBe(1);
        const desc = Object.getOwnPropertyDescriptor(result, 'computed');
        expect(typeof desc.get).toBe('function');
    });

    it('later source overwrites earlier source for same key', () => {
        const a = { x: 1 };
        const b = { x: 99 };
        const result = mergeStore(a, b);
        expect(result.x).toBe(99);
    });

    it('returns a plain object (not frozen)', () => {
        const result = mergeStore({ a: 1 });
        expect(() => { result.b = 2; }).not.toThrow();
    });
});
