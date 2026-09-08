import { mergeStore } from './utils.js';
import { uiStateModule } from './ui-state.js';
import { treeStateModule } from './tree-state.js';
import { selectionStateModule } from './selection-state.js';
import { integrationStateModule } from './integration-state.js';
import { selectionModule } from './selection.js';



export function makeBaseStore() {
    return mergeStore(
        uiStateModule,
        treeStateModule,
        selectionStateModule,
        integrationStateModule,
        selectionModule,
    );
}
