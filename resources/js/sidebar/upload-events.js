import Alpine from 'alpinejs';
import { t } from './i18n.js';
import { hasStateFlag, setStateFlag } from './state.js';
import { cacheInvalidateFolder, cacheInvalidateScreen } from './static-list/cache.js';
import { memInvalidateFolder, memClear } from './media-grid-cache.js';
import { onMediaFrameReady } from './media-frame-watcher.js';

const OPEN_KEY = '_plathixOriginalOpen';
const SEND_KEY = '_plathixOriginalSend';
const PATCHED_KEY = '_plathixUploadPatched';
const NOTICE_KEY = 'upload-session';

export function bindUploadCompleteEvents() {
    if (hasStateFlag('uploadEventsBound')) {
        return;
    }

    let _uploadCount = 0;
    let _uploadTimer = null;
    let _activeUploads = 0;
    let _uploadLockedFolder = 0;
    // Намерение фиксируется на СТАРТЕ загрузки (первый send), а не читается заново на каждый
    // XHR ([internal]): иначе навигация в другую папку во время загрузки перебивала бы target.
    // Отдельный boolean-флаг, а не число: _uploadLockedFolder=0 — легитимный root («Медиафайлы»/
    // системная папка), его нельзя отличить от «ещё не залочено» одним числом. Флаг разводит
    // «залочено ли сессия» и «на какую папку». Сбрасывается только в endSession.
    let _uploadSessionLocked = false;
    let _resetTimer = null;
    let _queueBound = false;
    const queueResetFallbackMs = 2500;
    const immediateResetFallbackMs = 2500;

    // [internal] (переоткрыт): на bulk-загрузке в папку с большим объёмом файлов каждый
    // успешно загруженный файл раньше вызывал refreshMediaFrame() немедленно — WP core
    // library._requery() синхронно очищает грид в [] на каждый вызов (mirror() core:
    // this.reset([], {silent:true})), а новый AJAX-реквери на 3000+ файлов идёт 2-5с.
    // N файлов подряд давали N гоняющихся реквери, каждый заново обнулял грид раньше,
    // чем предыдущий успевал долететь — пользователь видел пустую сетку весь batch.
    // Debounce с leading:true сохраняет прогрессивное появление первого/одиночного
    // файла ([internal]: мгновенный вызов, не задержанный) — trailing+maxWait гарантирует
    // ровно один финальный догоняющий вызов на плотный поток файлов, без риска зависнуть
    // навечно (в отличие от event-based in-flight guard — WP Senior Dev skeptic pass:
    // не проверено, гарантированно ли WP core эмитит завершающее событие на всех путях
    // `_requery`, значит guard без safety-timeout мог бы зависнуть; debounce сам себя
    // резолвит через таймер независимо от внутреннего поведения Backbone).
    const REFRESH_DEBOUNCE_MS = 300;
    const REFRESH_MAX_WAIT_MS = 1000;
    let _refreshDebounceTimer = null;
    let _refreshMaxWaitTimer = null;
    const flushRefreshMediaFrame = () => {
        clearTimeout(_refreshDebounceTimer);
        clearTimeout(_refreshMaxWaitTimer);
        _refreshDebounceTimer = null;
        _refreshMaxWaitTimer = null;
        getStore()?.refreshMediaFrame?.();
    };
    const debouncedRefreshMediaFrame = () => {
        const store = getStore();
        if (!store) return;

        const isLeadingCall = _refreshDebounceTimer === null && _refreshMaxWaitTimer === null;
        if (isLeadingCall) {
            // Leading edge: тихое окно, вызываем немедленно (сохраняет [internal]).
            store.refreshMediaFrame?.();
        }

        clearTimeout(_refreshDebounceTimer);
        _refreshDebounceTimer = setTimeout(() => {
            if (!isLeadingCall) {
                // Trailing edge: были повторные файлы после leading-вызова — один
                // догоняющий refresh коалесцирует всё, что случилось за окно тишины.
                flushRefreshMediaFrame();
            } else {
                clearTimeout(_refreshMaxWaitTimer);
                _refreshDebounceTimer = null;
                _refreshMaxWaitTimer = null;
            }
        }, REFRESH_DEBOUNCE_MS);

        if (_refreshMaxWaitTimer === null) {
            _refreshMaxWaitTimer = setTimeout(() => {
                // Плотный поток файлов не давал тишине наступить — maxWait гарантирует
                // финальный вызов независимо от того, что поток продолжается.
                flushRefreshMediaFrame();
            }, REFRESH_MAX_WAIT_MS);
        }
    };

    const getStore = () => Alpine.store('plathix');
    const getLockedFolderName = () => {
        const store = getStore();
        return store?.folders?.find((folder) => Number(folder.id) === Number(_uploadLockedFolder))?.name || '';
    };
    const syncStoreState = () => {
        const store = getStore();
        if (!store) return;
        store.isUploading = _activeUploads > 0;
        store.activeUploadCount = _activeUploads;
        store.uploadLockedFolder = _uploadLockedFolder;
    };
    const renderSessionNotice = () => {
        const store = getStore();
        if (!store) return;
        if (_activeUploads <= 0 || _uploadLockedFolder <= 0) {
            store.dismissNotificationByKey?.(NOTICE_KEY);
            return;
        }

        const lockedName = getLockedFolderName();
        const msg = lockedName
            ? t('upload_in_progress_folder_notice', `Upload is still running in "${lockedName}". The view will return there when it finishes.`)
            : t('upload_in_progress_notice', 'Upload is still running. The view will return to the upload folder when it finishes.');
        store.notify('info', msg, { key: NOTICE_KEY, duration: 0 });
    };
    const beginSession = () => {
        // _uploadLockedFolder не устанавливается здесь — только в proto.send
        // в момент реальной отправки, чтобы читать openId актуальным.
        clearTimeout(_resetTimer);
        _resetTimer = null;
        syncStoreState();
        renderSessionNotice();
    };
    const scheduleEndSession = (delayMs) => {
        clearTimeout(_resetTimer);
        _resetTimer = setTimeout(() => {
            if (_activeUploads === 0) {
                endSession();
            }
        }, delayMs);
    };
    const endSession = () => {
        const store = getStore();
        clearTimeout(_resetTimer);
        _resetTimer = null;
        syncStoreState();
        store?.dismissNotificationByKey?.(NOTICE_KEY);

        if (_uploadLockedFolder > 0) {
            cacheInvalidateFolder(_uploadLockedFolder);
            memInvalidateFolder(_uploadLockedFolder);
        }
        cacheInvalidateScreen('upload');
        memClear();

        const returnFolder = _uploadLockedFolder;
        _uploadLockedFolder = 0;
        _uploadSessionLocked = false;
        syncStoreState();

        if (!store || returnFolder <= 0) {
            return;
        }

        store.refreshFolders({ silent: true }).catch(() => {});

        if (Number(store.openId) !== returnFolder) {
            // User navigated away during upload — return to the upload folder.
            store.openId = returnFolder;
            store.applyFolderFilter(returnFolder, { resetPage: true });
            store.refreshMediaFrame?.();
        } else {
            // [internal]: WP's own backbone uploader adds the new model to the GLOBAL
            // Attachments.all collection the moment the XHR completes, but the active
            // grid query filters by plathix_folder (a Plathix-specific taxonomy term) —
            // WP core client-side code has no knowledge of that field and cannot decide
            // whether the new model belongs to the current filtered view without asking
            // the server. root/"Все файлы" (returnFolder<=0) never reaches this branch —
            // the early return above keeps the "no reset-fetch on large libraries"
            // perf invariant intact there, where native pickup is confirmed to work.
            store.refreshMediaFrame?.();
        }
    };
    const bindQueue = () => {
        if (_queueBound) return;
        const queue = window.wp?.Uploader?.queue;
        if (!queue?.on) return;
        _queueBound = true;

        queue.on('add', () => {
            beginSession();
        });
        queue.on('reset', () => {
            if (_activeUploads === 0) {
                endSession();
            }
        });
    };

    const onUpload = () => {
        const store = getStore();
        if (!store) return;

        _uploadCount++;
        clearTimeout(_uploadTimer);
        _uploadTimer = setTimeout(() => {
            const count = _uploadCount;
            _uploadCount = 0;
            const msg = count === 1
                ? t('file_uploaded_notif', 'File uploaded')
                : count + ' ' + t('files_uploaded_notif', 'files uploaded');
            Alpine.store('plathix')?.notify('success', msg);
        }, 800);
    };

    const proto = XMLHttpRequest.prototype;
    if (proto[PATCHED_KEY]) {
        setStateFlag('uploadEventsBound');
        return;
    }

    proto[OPEN_KEY] = proto.open;
    proto[SEND_KEY] = proto.send;

    /** @this {XMLHttpRequest} */
    proto.open = function (_method, url) {
        this._isWpUpload = typeof url === 'string' && url.includes('async-upload.php');
        this._isAjaxUpload = typeof url === 'string' && url.includes('admin-ajax.php');
        if (this._isWpUpload) {
            bindQueue();
            beginSession();
            _activeUploads++;
            syncStoreState();
            renderSessionNotice();
            // [internal]: ОДИН 'loadend' вместо пары 'load'/'error' — loadend диспатчится
            // браузером ровно раз на ЛЮБОЙ терминальный исход реального XHR (success, error,
            // abort(), timeout), в отличие от 'load'/'error', которые не покрывают abort/timeout
            // вовсе и оставляли _activeUploads навсегда >0 на этих путях.
            this.addEventListener('loadend', function () {
                _activeUploads = Math.max(0, _activeUploads - 1);
                syncStoreState();
                if (_activeUploads === 0) {
                    scheduleEndSession(_queueBound ? queueResetFallbackMs : immediateResetFallbackMs);
                }
                if (this.status >= 200 && this.status < 300) {
                    onUpload();
                    // [internal] (progressive): каждый успешно загруженный файл виден в
                    // открытой папке сразу, не дожидаясь конца всей bulk-сессии. root
                    // (_uploadLockedFolder<=0) не задет — perf-инвариант не нарушен.
                    // [internal] (переоткрыт): debounced, не прямой вызов — см. комментарий
                    // у debouncedRefreshMediaFrame выше.
                    if (_uploadLockedFolder > 0) {
                        debouncedRefreshMediaFrame();
                    }
                }
            });
        }
        return proto[OPEN_KEY].apply(this, arguments);
    };

    /** @this {XMLHttpRequest} */
    proto.send = function (data) {
        const isAjaxUpload = this._isAjaxUpload
            && data instanceof FormData
            && data.get('action') === 'upload-attachment';

        if (isAjaxUpload) {
            bindQueue();
            beginSession();
            _activeUploads++;
            syncStoreState();
            renderSessionNotice();
            // [internal]: см. тот же комментарий в proto.open выше — единый 'loadend'.
            this.addEventListener('loadend', function () {
                _activeUploads = Math.max(0, _activeUploads - 1);
                syncStoreState();
                if (_activeUploads === 0) {
                    scheduleEndSession(_queueBound ? queueResetFallbackMs : immediateResetFallbackMs);
                }
                if (this.status >= 200 && this.status < 300) {
                    onUpload();
                    // [internal] (progressive): см. тот же комментарий в proto.open выше.
                    if (_uploadLockedFolder > 0) {
                        debouncedRefreshMediaFrame();
                    }
                }
            });
        }

        const isWpUpload = this._isWpUpload || isAjaxUpload;
        if (isWpUpload && data instanceof FormData) {
            // Лочим папку намерения на ПЕРВОМ send этой upload-сессии ([internal]). Далее весь
            // batch (и любая навигация во время загрузки) читает залоченное значение, а не
            // текущий openId. openId=0 — легитимный root («Медиафайлы»/системная папка): в этом
            // случае plathix_folder НЕ отправляется, сервер трактует отсутствие поля как root
            // (файл без назначения в пользовательскую папку).
            if (!_uploadSessionLocked) {
                _uploadLockedFolder = Number(getStore()?.openId) || 0;
                _uploadSessionLocked = true;
                syncStoreState();
                renderSessionNotice();
            }

            // [internal]: «Корзина» не валидный upload-target (тот же инвариант, что #235
            // установил для move/drag-drop) — прерываем саму отправку, не только пропускаем
            // plathix_folder. Guard идёт ПОСЛЕ присвоения _uploadLockedFolder выше (WP Senior
            // Dev skeptic finding): на первом файле сессии проверка иначе не увидит значение.
            // [internal]: диспатчим synthetic 'loadend' (не 'error') — это единственное
            // событие, на которое подписан обработчик выше (proto.open/proto.send) после
            // перехода на единый loadend-листенер. Этот XHR никогда не доходит до
            // proto[SEND_KEY] (return ниже), значит браузер сам никогда не сгенерирует
            // loadend для него — без явного synthetic dispatch здесь _activeUploads
            // остался бы навсегда инкрементирован (concurrency-скептик, паковка [internal]).
            // store.notify('error', ...) ниже остаётся отдельным user-facing уведомлением,
            // не завязанным на тип диспатчимого события.
            const trashFolderId = Number(window.Plathix?.trashFolderId || 0);
            if (trashFolderId > 0 && _uploadLockedFolder === trashFolderId) {
                getStore()?.notify?.(
                    'error',
                    t('upload_blocked_in_trash', 'Go to your active media library to upload new files.')
                );
                this.dispatchEvent(new ProgressEvent('loadend'));
                return undefined;
            }

            if (_uploadLockedFolder > 0) {
                data.append('plathix_folder', String(_uploadLockedFolder));
            }
        }
        return proto[SEND_KEY].apply(this, arguments);
    };

    proto[PATCHED_KEY] = true;
    bindQueue();
    // [internal] ([internal]): wp.media.on/wp.media.events.on('open') не работают —
    // media-frame-watcher.js единственный владелец обнаружения frame через DOM.
    onMediaFrameReady(bindQueue);
    setStateFlag('uploadEventsBound');
}
