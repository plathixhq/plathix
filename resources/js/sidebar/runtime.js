/**
 * @returns {PlathixRuntime}
 */
export function getRuntime() {
    return /** @type {PlathixRuntime} */ (window.Plathix || {});
}

export function getScreenKind() {
    return getRuntime().screenKind || 'static';
}

export function isStaticScreen() {
    return getScreenKind() === 'static';
}

export function getScreenBase() {
    return getRuntime().screenBase || '';
}

export function getPostType() {
    return getRuntime().postType || 'attachment';
}



export function getMediaMode() {
    const urlMode = new URL(window.location.href).searchParams.get('mode');
    if (urlMode === 'grid' || urlMode === 'list') {
        return urlMode;
    }

    return getRuntime().mediaMode || 'grid';
}

export function getFilterStrategy() {
    const runtime = getRuntime();
    if (runtime.screenKind === 'modal') {
        return 'media-frame';
    }

    if (runtime.screenBase === 'upload' && getMediaMode() === 'grid') {
        return 'media-frame';
    }





    return runtime.filterStrategy || 'url';
}

export function shouldUseMediaFrameFiltering() {
    return getFilterStrategy() === 'media-frame';
}

export function shouldUseStaticListFiltering() {
    return getFilterStrategy() === 'static-list';
}



export function resolveMediaFrame() {
    const frame = window.wp?.media?.frame;
    if (frame) {
        return frame;
    }

    const frames = window.wp?.media?.frames;
    if (frames && typeof frames === 'object') {
        for (const candidate of Object.values(frames)) {
            if (candidate && typeof candidate.on === 'function') {
                return candidate;
            }
        }
    }

    return undefined;
}

export function getMediaFrame() {
    if (!shouldUseMediaFrameFiltering()) {
        return null;
    }

    return resolveMediaFrame() || null;
}

export function isUploadScreen() {
    return getScreenBase() === 'upload';
}

export function isStaticLibraryScreen() {
    return !!getRuntime().isStaticLibraryScreen;
}



export function isTrashViewFromUrl(url = window.location.href) {
    try {
        const params = new URL(url, window.location.origin).searchParams;
        if (params.get('attachment-filter') === 'trash') {
            return true;
        }
        const status = params.get('status') || params.get('post_status') || '';
        return status === 'trash';
    } catch {
        return false;
    }
}



let _trashViewActiveSnapshot = isTrashViewFromUrl();

window.wp?.hooks?.addAction?.('plathix.folderFilterApplied', 'plathix/trash-view-context-owner', (...args) => {
    const payload = /** @type {{ folderId?: number }|undefined} */ (args[0]);
    const trashId = Number(getRuntime().trashFolderId || 0);
    _trashViewActiveSnapshot = trashId > 0 && Number(payload?.folderId) === trashId;
});

export function isTrashViewActive() {
    return _trashViewActiveSnapshot;
}

/**
 * Returns the configured folder nesting depth limit.
 * 0 means unlimited — callers must treat 0 as "no restriction".
 */
export function getDepthLimit() {
    return Number(getRuntime().depthLimit ?? 0);
}

/**
 * Returns resolved feature flags. dnd/uploadSync default to true unless explicitly
 * set to false, so existing deployments are unaffected.
 *
 * @returns {{ dnd: boolean, uploadSync: boolean }}
 */
export function getFeatures() {
    const runtime = getRuntime();
    return {




        dnd:        runtime.dnd        !== false,
        uploadSync: runtime.uploadSync !== false,



    };
}
