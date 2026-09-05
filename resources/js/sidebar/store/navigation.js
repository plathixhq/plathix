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

/**
 * Fallback-путь: реконструирует "полно ли дерево" по параметрам запроса, когда сервер
 * не прислал `fullTree` в ответе (version skew — старый сервер без [internal] payload-флага).
 * Источник истины теперь — `data.fullTree` (FolderReadController::get_folders()), эта функция
 * больше не вызывается на happy path (см. refreshFolders: `isFull`).
 *
 * Зеркало четырёх параметров эндпоинта (FolderReadController.php:30-41). Без `parent_id`
 * запрос всегда уходит в full-tree ветку (`:52` → `get_all_cached()`), но полнота ответа
 * этим не гарантирована — внутри той же ветки сервер ещё и режет результат:
 *   - `search` (`:94-105`)  — фильтрует состав по имени;
 *   - `ids`    (`:107-118`) — фильтрует состав по id;
 *   - `fields` (`:120-155`) — режет поля (нет parentId/hasChildren — дерево не построить).
 *
 * Проверка по значению, а не по наличию ключа: buildQuery (api/transport.js:199)
 * отбрасывает undefined/null/'' и пустые массивы, поэтому `{search: undefined}` даёт
 * ровно тот же запрос, что и отсутствие ключа.
 *
 * @param {Record<string, unknown>} params
 */
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
                // [internal]: источник истины — серверный data.fullTree (FolderReadController).
                // Skew-safe fallback на isFullTreeRequest(params), если сервер ещё не шлёт поле
                // (старый сервер без этого пакета) — typeof-проверка отличает boolean от undefined.
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
                    // [internal]: флаг выводится из факта загрузки, а не требуется от
                    // вызывающего. Раньше 11 из 13 вызовов его не передавали, и в
                    // lazy-режиме hasLoadedFullTree залипал в false при фактически полном
                    // дереве — дальше hasLoadedChildren() (tree-state.js:18) отвечал false
                    // на загруженные ветки, и уходили повторные запросы.
                    this.hasLoadedFullTree = true;
                }

                // Пара (hasLoadedFullTree, loadedParentIds) описывает одно состояние и
                // обновляется атомарно, внутри requestId-guard. Раньше пересборка Set жила
                // в loadCompleteFolderTree ПОСЛЕ await, вне защиты: устаревший ответ успевал
                // затереть её результатом уже неактуального запроса.
                //
                // [internal]: гейт — тот же предикат, что у флага выше, а НЕ сам флаг.
                // hasLoadedFullTree липкий (в не-lazy режиме поднят с бутстрапа,
                // tree-state.js:10, и не сбрасывается ничем), поэтому гейт по нему
                // пересобирал Set даже из усечённого ответа: search/ids/parent_id режут
                // состав, и валидные id родителей исчезали из набора. Пересобирать можно
                // только тогда, когда ответ действительно содержит всё дерево.
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
            // markFullTree здесь не передаётся: частичная догрузка не может сделать полное
            // дерево неполным, а безусловное markFullTree:false гасило бы корректный флаг
            // ([internal]). Вывод и так не сработает — replace:false его блокирует.
            params: { parent_id: normalizedParentId },
        });
    },

    async loadCompleteFolderTree({ silent = true, signal = undefined } = {}) {
        // Пересборку loadedParentIds ведёт refreshFolders — там она защищена
        // requestId-guard'ом и выполняется атомарно с флагом полноты ([internal]).
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
        // [internal]: сброс выбора через владельца (store/selection.js).
        this.clearSelectionDom();
        this.selected = [];
        this.openId = Number(id);

        // [internal] ([internal]): раскрыть путь до папки в основном дереве ДО рендера,
        // чтобы узел был в DOM (и в deferred-режиме дети предков догружены) к моменту
        // focus/scroll. Иначе клик по папке из «Избранного» со свёрнутой веткой оставляет
        // узел неотрисованным, а focus() уходит в null.
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
            // [internal]: глубокая папка может быть за пределами вьюпорта сайдбара;
            // focus() не гарантирует скролл на длинном дереве. «В DOM» ≠ «видно пользователю».
            node?.scrollIntoView({ block: 'nearest' });
        });
    },

	refreshMediaFrame() {
        const mediaFrame = getMediaFrame();
        if (!mediaFrame) {
            return;
        }

        // [internal] (обнаружено при реализации): голый .fetch({reset:true}) на
        // content.collection/library бросает Backbone "A 'url' property or function
        // must be specified" в grid-контексте — подтверждено фактом на живом стенде.
        // library._requery(true) — тот же WP core API, которым ядро форсит перезапрос
        // этой же коллекции (media-views.js), без props-реактивности, которая покрывает
        // applyFolderFilter/_retryMediaFrameFolderFilter (там props.set() сам триггерит
        // requery через Backbone change-событие) — здесь критерии не меняются, нужен
        // именно force re-fetch с текущими props.
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
				const content = mediaFrame?.content?.get?.();
				const collection = content?.collection;
				if (collection?.props) {
					applyPropsFilter(collection.props, active, trashId);
					try { collection.fetch({ reset: true }); } catch (e) {}
					return;
				}

				const library = /** @type {PlathixMediaLibrary | null | undefined} */ (
					mediaFrame?.state?.()?.get?.('library')
				);
				if (library?.props) {
					applyPropsFilter(library.props, active, trashId);
					try { library.fetch({ reset: true }); } catch (e) {}
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

		// [internal]: единственная точка, куда сходятся ВСЕ пути смены видимого grid-фильтра
		// (клик по папке через openFolder(), автоперевод после удаления, и обходной путь
		// upload-events.js — returnFolder после фонового аплоада меняет openId и зовёт
		// applyFolderFilter НАПРЯМУЮ, минуя openFolder()/plathix.folderOpened). Диспатчим
		// на каждый вызов, включая no-op с тем же active — потребитель (runtime.js) сам
		// решает, изменился ли контекст.
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
            // [internal] ([internal]): инвалидировать любой фоновый
            // _retryMediaFrameFolderFilter от предыдущего вызова здесь, а не только
            // внутри самой retry-функции — иначе быстрая смена папки (второй вызов
            // применяет фильтр синхронно, props уже готовы) не отменяет устаревший
            // retry-цикл первой папки, и он может применить свой фильтр позже поверх
            // уже актуального состояния.
            ++_mediaFrameRetryToken;
            try {
                const content = mediaFrame?.content?.get?.();
                const collection = content?.collection;
                if (collection?.props) {
                    const currentFolder = Number(collection.props.get?.('plathix_folder')) || 0;
                    if (currentFolder === active && !resetPage) {
                        return;
                    }
                    applyPropsFilter(collection.props, active, trashId);
                    try {
                        mediaFrame?.state?.()?.get?.('selection')?.reset?.();
                        document.querySelectorAll('.attachment.selected').forEach((el) => el.classList.remove('selected'));
                    } catch (e) {}
                    try { collection.fetch({ reset: true }); } catch (e) {}
                    return;
                }

                /** @type {PlathixMediaLibrary | null | undefined} */
                const library = /** @type {PlathixMediaLibrary | null | undefined} */ (
                    mediaFrame?.state?.()?.get?.('library')
                );
                if (library?.props) {
                    const currentFolder = Number(library.props.get?.('plathix_folder')) || 0;
                    if (currentFolder === active && !resetPage) {
                        return;
                    }
                    applyPropsFilter(library.props, active, trashId);
                    try {
                        mediaFrame?.state?.()?.get?.('selection')?.reset?.();
                        document.querySelectorAll('.attachment.selected').forEach((el) => el.classList.remove('selected'));
                    } catch (e) {}
                    try { library.fetch({ reset: true }); } catch (e) {}
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
            // [internal]: только attachment-filter=trash; JS-детект читает его первым ([internal]).
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
