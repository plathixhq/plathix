import { getRuntime } from '../runtime.js';

const runtime = getRuntime();

export const selectionStateModule = {
    selected: [],
    bulkSafeMode: runtime.bulkSafeMode ?? true,
};
