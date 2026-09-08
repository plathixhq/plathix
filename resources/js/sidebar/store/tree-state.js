import { getRuntime } from '../runtime.js';

const runtime = getRuntime();





let _foldersVersion = 0;

export const treeStateModule = {
    folders: runtime.folders ?? [],
    openId: Number(runtime.openId) || 0,
    FOLDER_ALL: 0,
    FOLDER_UNCATEGORIZED: 0,
    hasLoadedFullTree: !runtime.deferFoldersBootstrap,
    loadedParentIds: new Set((runtime.bootstrapLoadedParents ?? [0]).map((id) => Number(id) || 0)),

    shouldUseDeferredTree() {
        return !!runtime.deferFoldersBootstrap;
    },

    hasLoadedChildren(parentId) {
        return this.hasLoadedFullTree || this.loadedParentIds.has(Number(parentId) || 0);
    },

    markChildrenLoaded(parentId) {
        this.loadedParentIds.add(Number(parentId) || 0);
    },

    mergeFolders(nextFolders = []) {
        const merged = new Map(this.folders.map((folder) => [Number(folder.id), folder]));
        for (const folder of nextFolders) {
            merged.set(Number(folder.id), folder);
        }
        this.folders = Array.from(merged.values());
        _foldersVersion++;
    },





    patchFolder(id, patch) {
        const idx = this.folders.findIndex((f) => Number(f.id) === Number(id));
        if (idx === -1) return false;
        this.folders.splice(idx, 1, { ...this.folders[idx], ...patch });
        _foldersVersion++;
        return true;
    },

    get foldersVersion() {
        return _foldersVersion;
    },

    insertSorted(folders, folder) {
        return [...folders, folder].sort(
            (a, b) => (Number(a.position || 0) - Number(b.position || 0)) || String(a.name || '').localeCompare(String(b.name || ''))
        );
    },

    get canView() {
        return runtime.caps?.canView ?? false;
    },

    get canAssign() {
        return runtime.caps?.canAssign ?? false;
    },

    get canManage() {
        return runtime.caps?.canManage ?? false;
    },

    get canZipDownload() {
        return runtime.caps?.canZipDownload ?? false;
    },
};
