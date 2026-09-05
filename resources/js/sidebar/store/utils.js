export function mergeStore(...sources) {
    const result = {};
    for (const source of sources) {
        Object.defineProperties(result, Object.getOwnPropertyDescriptors(source));
    }
    return result;
}
