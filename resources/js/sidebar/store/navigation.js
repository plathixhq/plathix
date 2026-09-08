import Alpine from 'alpinejs';
import { doAction } from '../hooks.js';
import { Api } from '../api.js';
import { t } from '../i18n.js';
import { getMediaFrame, getPostType, getRuntime, shouldUseMediaFrameFiltering, shouldUseStaticListFiltering } from '../runtime.js';
import { getStaticListManager, initStaticListNavigation } from '../static-list/index.js';
import { cacheClear } from '../static-list/cache.js';

let _refreshRequestSeq = 0;
let _loadingRequestSeq = 0;
let _mediaFrameRetryToken = 0;



function isFullTreeRequest(params) {
    const isEmpty = (value) => value === undefined || value === null || value === ''
        || (Array.isArray(value) && value.length === 0);

    return isEmpty(params.parent_id)
        && isEmpty(params.search)
        && isEmpty(params.ids)
        && isEmpty(params.fields);
}

/** @param {{ set?: Function, unset?: Function }} props @param {number} active @param {number} trashId */
function applyPropsFilter(props, active, trashId) {
    props.unset?.('status', { silent: true });
    props.unset?.('post_status', { silent: true });
    props.unset?.('plathix_folder', { silent: true });
    if (active === trashId && trashId > 0) {
        props.set?.({ status: 'trash', post_status: 'trash' });
    } else if (active > 0) {
        props.set?.({ plathix_folder: active });
    } else {
        props.set?.({ plathix_folder: 0 }, { silent: true });
        props.unset?.('plathix_folder');
    }
}



function resolveMediaFrameTarget(mediaFrame) {
    const content = mediaFrame?.content?.get?.();
    const collection = content?.collection;
    if (collection?.props) {
        return { target: collection, isCollection: true };
    }

    const library = /** @type {PlathixMediaLibrary | null | undefined} */ (
        mediaFrame?.state?.()?.get?.('library')
    );
    if (library?.props) {
        return { target: library, isCollection: false };
    }

    return null;
}


export const navigationModule = {
    async refreshFolders({ silent = false, params = {}, signal = undefined, replace = true, markParentLoaded = null, markFullTree = null, skipCacheClear = false } = {}) {
        if (!skipCacheClear) {
            cacheClear();
        }
        const requestId = ++_refreshRequestSeq;
        if (!silent) {
            this.isLoading = true;
            _loadingRequestSeq = requestId;
        }
        this.error = null;

        try {
            const data = await Api.getFolders(params, signal);
            if (requestId === _refreshRequestSeq) {



                const isFull = typeof data?.fullTree === 'boolean' ? data.fullTree : isFullTreeRequest(params);
                const nextFolders = Array.isArray(data?.folders) ? data.folders : [];
                if (replace) {
                    this.folders = nextFolders;
                } else {
                    this.mergeFolders(nextFolders);
                }
                if (markParentLoaded !== null) {
                    this.markChildrenLoaded(markParentLoaded);
                }
                if (markFullTree === true) {
                    this.hasLoadedFullTree = true;
                } else if (markFullTree === false) {
                    this.hasLoadedFullTree = false;
                } else if (replace && isFull) {





                    this.hasLoadedFullTree = true;
                }





                //






                if (replace && isFull) {
                    this.loadedParentIds = new Set([0]);
                    for (const folder of this.folders) {
                        if (folder?.hasChildren) {
                            this.loadedParentIds.add(Number(folder.id) || 0);
                        }
                    }
                }
            }
            return data;
        } catch (error) {
            if (requestId === _refreshRequestSeq && error?.name !== 'AbortError') {
                this.error = error.message;
            }
            throw error;
        } finally {
            if (!silent && _loadingRequestSeq === requestId) {
                this.isLoading = false;
            }
        }
    },

    async loadFolderChildren(parentId, { silent = true, signal = undefined } = {}) {
        const normalizedParentId = Number(parentId) || 0;
        if (this.hasLoadedChildren(normalizedParentId)) {
            return null;
        }

        return this.refreshFolders({
            silent,
            signal,
            replace: false,
            markParentLoaded: normalizedParentId,



            params: { parent_id: normalizedParentId },
        });
    },

    async loadCompleteFolderTree({ silent = true, signal = undefined } = {}) {


        return this.refreshFolders({
            silent,
            signal,
            replace: true,
            markFullTree: true,
        });
    },

    onAttachmentDeleted() {
        const folder = this.folders.find((item) => Number(item.id) === Number(this.openId));
        if (!folder) {
            return;
        }

        Api.getFolderCount(folder.id)
            .then((count) => {
                this.patchFolder(folder.id, { count });
            })
            .catch(() => {});
    },

    async openFolder(id) {

        this.clearSelectionDom();
        this.selected = [];
        this.openId = Number(id);





        await this.expandAncestors(Number(id));

        if (this.isUploading && Number(this.uploadLockedFolder) > 0 && Number(id) !== Number(this.uploadLockedFolder)) {
            const lockedFolder = this.folders.find((item) => Number(item.id) === Number(this.uploadLockedFolder));
            const lockedName = lockedFolder?.name || '';
            const msg = lockedName
                ? t('upload_in_progress_folder_notice', `Upload is still running in "${lockedName}". The view will return there when it finishes.`)
                : t('upload_in_progress_notice', 'Upload is still running. The view will return to the upload folder when it finishes.');
            this.notify('info', msg, { key: 'upload-session', duration: 0 });
        }

        this.applyFolderFilter(this.openId, { resetPage: true });

        doAction('plathix.folderOpened', { folderId: this.openId, postType: getPostType() });

        Api.savePreference('open_folder_id', id).catch(() => {});

        Alpine.nextTick(() => {
            /** @type {HTMLElement | null} */
            const node = document.querySelector(`[data-folder-id="${id}"]`);
            node?.focus();


            node?.scrollIntoView({ block: 'nearest' });
        });
    },

	refreshMediaFrame() {
        const mediaFrame = getMediaFrame();
        if (!mediaFrame) {
            return;
        }









        try {
            /** @type {PlathixMediaLibrary | null | undefined} */
            const library = /** @type {PlathixMediaLibrary | null | undefined} */ (
                mediaFrame?.state?.()?.get?.('library')
            );
            if (typeof library?._requery === 'function') {
                library._requery(true);
            }
        } catch (e) {
        }
	},

	_retryMediaFrameFolderFilter(folderId, trashId, maxRetries = 20) {
		const active = Number(folderId) || 0;
		const token = ++_mediaFrameRetryToken;
		let retries = 0;

		const tryApply = () => {
			if (token !== _mediaFrameRetryToken) {
				return;
			}

			const mediaFrame = getMediaFrame();
			if (!mediaFrame) {
				if (++retries < maxRetries) {
					setTimeout(tryApply, 150);
				}
				return;
			}

			try {
				const resolved = resolveMediaFrameTarget(mediaFrame);
				if (resolved) {
					applyPropsFilter(resolved.target.props, active, trashId);
					try { resolved.target.fetch({ reset: true }); } catch (e) {}
					return;
				}
			} catch (e) {
			}

			if (++retries < maxRetries) {
				setTimeout(tryApply, 150);
			}
		};

		tryApply();
	},

	applyFolderFilter(folderId, { resetPage = false } = {}) {
		const active = Number(folderId) || 0;
		const trashId = Number(getRuntime().trashFolderId ?? 0);







		doAction('plathix.folderFilterApplied', { folderId: active });

		// static-list screens (upload list, edit.php) — never go through mediaFrame
		if (shouldUseStaticListFiltering()) {
			let manager = getStaticListManager();
			if (!manager) {
				try {
					initStaticListNavigation();
					manager = getStaticListManager();
				} catch (e) {}
			}
			if (manager) {
				const targetUrl = manager.buildUrl(active, { resetPage });
				if (targetUrl) {
					manager.navigate(targetUrl, { folderId: active });
					return;
				}
			}
			return;
		}

		const mediaFrame = getMediaFrame();
        if (mediaFrame) {






            ++_mediaFrameRetryToken;
            try {
                const resolved = resolveMediaFrameTarget(mediaFrame);
                if (resolved) {
                    const currentFolder = Number(resolved.target.props.get?.('plathix_folder')) || 0;
                    if (currentFolder === active && !resetPage) {
                        return;
                    }
                    applyPropsFilter(resolved.target.props, active, trashId);
                    try {
                        mediaFrame?.state?.()?.get?.('selection')?.reset?.();
                        document.querySelectorAll('.attachment.selected').forEach((el) => el.classList.remove('selected'));
                    } catch (e) {}
                    try { resolved.target.fetch({ reset: true }); } catch (e) {}
                    return;
				}
			} catch (e) {
			}

			this._retryMediaFrameFolderFilter(active, trashId);
			return;
		}

		if (shouldUseMediaFrameFiltering()) {
			return;
		}

		const url = new URL(window.location.href);
        if (active === trashId && trashId > 0) {
            url.searchParams.delete('plathix_folder');

            url.searchParams.set('attachment-filter', 'trash');
            url.searchParams.delete('post_status');
        } else if (active > 0) {
            url.searchParams.set('plathix_folder', String(active));
            url.searchParams.delete('status');
            url.searchParams.delete('post_status');
            url.searchParams.delete('attachment-filter');
        } else {
            url.searchParams.delete('plathix_folder');
            url.searchParams.delete('status');
            url.searchParams.delete('post_status');
            url.searchParams.delete('attachment-filter');
        }
        if (resetPage) {
            url.searchParams.delete('paged');
        }

        window.location.assign(url.toString());
    },
};
