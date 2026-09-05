import { mergeStore } from './utils.js';
import { uiStateModule } from './ui-state.js';
import { treeStateModule } from './tree-state.js';
import { selectionStateModule } from './selection-state.js';
import { integrationStateModule } from './integration-state.js';
import { selectionModule } from './selection.js';

/**
 * Тестовая база store вместо мёртвого core.js ([internal]).
 * Собирается из реальных prod-модулей, покрывающих base-вызовы this.* у тестируемых
 * модулей (withLoading из ui-state, mergeFolders/getters из tree-state и т.д.).
 * Тесты по-прежнему Object.assign'ят поверх недостающее (folders, refreshFolders, ...).
 */
export function makeBaseStore() {
    return mergeStore(
        uiStateModule,
        treeStateModule,
        selectionStateModule,
        integrationStateModule,
        selectionModule,
    );
}
