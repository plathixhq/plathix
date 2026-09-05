jest.mock('alpinejs', () => ({
    store: jest.fn(),
}));

jest.mock('../i18n.js', () => ({
    t: (_key, fallback) => fallback,
}));

jest.mock('../state.js', () => ({
    hasStateFlag: jest.fn(() => false),
    setStateFlag: jest.fn(),
}));

jest.mock('../static-list/cache.js', () => ({
    cacheInvalidateFolder: jest.fn(),
    cacheInvalidateScreen: jest.fn(),
}));

jest.mock('../media-frame-watcher.js', () => ({
    onMediaFrameReady: jest.fn(),
}));

import Alpine from 'alpinejs';
import { bindUploadCompleteEvents } from '../upload-events.js';
import { cacheInvalidateFolder, cacheInvalidateScreen } from '../static-list/cache.js';
import { onMediaFrameReady } from '../media-frame-watcher.js';

class FakeXHR {
    constructor() {
        this.listeners = {};
        this.status = 0;
        this._isWpUpload = false;
    }

    addEventListener(type, handler) {
        this.listeners[type] = handler;
    }

    // Реальный XMLHttpRequest наследует dispatchEvent от EventTarget — прод-код
    // (upload-events.js, [internal]/#690) полагается на него для synthetic loadend-события.
    dispatchEvent(event) {
        this.trigger(event.type);
        return true;
    }

    open(_method, url) {
        this._url = url;
        return undefined;
    }

    send(data) {
        this._data = data;
        return undefined;
    }

    trigger(type) {
        if (this.listeners[type]) {
            this.listeners[type].call(this);
        }
    }
}

const baseFakeOpen = FakeXHR.prototype.open;
const baseFakeSend = FakeXHR.prototype.send;

function makeQueue() {
    const handlers = {};

    return {
        on: jest.fn((event, handler) => {
            handlers[event] = handler;
        }),
        trigger(event) {
            handlers[event]?.();
        },
    };
}

describe('bindUploadCompleteEvents', () => {
    let OriginalXHR;
    let store;
    let queue;

    beforeEach(() => {
        jest.useFakeTimers();
        jest.clearAllMocks();

        FakeXHR.prototype.open = baseFakeOpen;
        FakeXHR.prototype.send = baseFakeSend;
        delete FakeXHR.prototype._plathixOriginalOpen;
        delete FakeXHR.prototype._plathixOriginalSend;
        delete FakeXHR.prototype._plathixUploadPatched;

        store = {
            openId: 7,
            folders: [{ id: 7, name: 'Folder 7' }],
            isUploading: false,
            activeUploadCount: 0,
            uploadLockedFolder: 0,
            refreshFolders: jest.fn().mockResolvedValue({}),
            applyFolderFilter: jest.fn(),
            refreshMediaFrame: jest.fn(),
            notify: jest.fn(),
            dismissNotificationByKey: jest.fn(),
        };
        Alpine.store.mockImplementation((name) => (name === 'plathix' ? store : null));

        queue = makeQueue();
        window.wp = {
            Uploader: { queue },
        };

        window.__PlathixState = {};

        OriginalXHR = global.XMLHttpRequest;
        global.XMLHttpRequest = FakeXHR;
    });

    afterEach(() => {
        jest.runOnlyPendingTimers();
        jest.useRealTimers();
        global.XMLHttpRequest = OriginalXHR;
        delete window.wp;
        delete window.__PlathixState;
    });

    it('does not end upload session before uploader queue reset arrives', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        const formData = new FormData();

        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(formData);
        xhr.status = 200;
        xhr.trigger('loadend');

        jest.advanceTimersByTime(1000);

        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        // [internal] (progressive): refreshMediaFrame теперь вызывается per-file на load,
        // не дожидаясь конца upload-сессии — уже сработал к этому моменту.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);
        expect(store.uploadLockedFolder).toBe(7);

        queue.trigger('reset');

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(7);
        expect(cacheInvalidateScreen).toHaveBeenCalledWith('upload');
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        // [internal] (progressive): вызывается дважды — один раз на load (per-file), ещё
        // раз в endSession (idempotent safety net) — оба вызова ожидаемы, не регрессия.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(2);
    });

    it('falls back to timed endSession when uploader queue reset never arrives', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(new FormData());
        xhr.status = 200;
        xhr.trigger('loadend');

        jest.advanceTimersByTime(2600);

        expect(cacheInvalidateFolder).toHaveBeenCalledWith(7);
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
        // [internal] (progressive): per-file на load + safety net в endSession.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(2);
    });

    it('[internal] perf invariant: root/"Все файлы" (openId=0) does not call refreshMediaFrame', () => {
        store.openId = 0;
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(new FormData());
        xhr.status = 200;
        xhr.trigger('loadend');

        jest.advanceTimersByTime(2600);

        // returnFolder<=0 → early return в endSession, WP native подхват уже работает
        // для root (подтверждено на стенде) — лишний reset-fetch не нужен.
        expect(store.refreshMediaFrame).not.toHaveBeenCalled();
        expect(store.refreshFolders).not.toHaveBeenCalled();
    });

    it('appends the locked folder id to upload FormData', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        const formData = new FormData();
        const appendSpy = jest.spyOn(formData, 'append');

        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(formData);

        expect(appendSpy).toHaveBeenCalledWith('plathix_folder', '7');
    });

    it('locks root at upload start from a system folder; mid-upload navigation does not redirect files ([internal])', () => {
        // Старт batch из СИСТЕМНОЙ папки «Медиафайлы» (openId=0). Первый send лочит target=root
        // (0). Пользователь уходит в папку 7 ВО ВРЕМЯ загрузки. Ни первый, ни последующие файлы
        // не должны получить plathix_folder — файлы остаются без пользовательской папки (root).
        store.openId = 0;
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        const firstData = new FormData();
        const firstSpy = jest.spyOn(firstData, 'append');
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(firstData); // лок ставится здесь: target=root(0)

        store.openId = 7; // навигация в папку 7 ВО ВРЕМЯ загрузки

        const second = new XMLHttpRequest();
        const secondData = new FormData();
        const secondSpy = jest.spyOn(secondData, 'append');
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(secondData);

        expect(firstSpy).not.toHaveBeenCalledWith('plathix_folder', expect.anything());
        expect(secondSpy).not.toHaveBeenCalledWith('plathix_folder', '7');
        expect(secondSpy).not.toHaveBeenCalledWith('plathix_folder', expect.anything());
    });

    it('locks a user folder at start; navigation during upload does not redirect later files ([internal] happy-path)', () => {
        // Старт batch из пользовательской папки 7 (первый send лочит target=7), затем во
        // время загрузки пользователь уходит в папку 99. Второй файл batch должен уехать
        // в залоченную папку 7, а не в текущую 99.
        store.openId = 7;
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        const firstData = new FormData();
        const firstSpy = jest.spyOn(firstData, 'append');
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(firstData); // лок ставится здесь: target=7

        store.openId = 99; // навигация ВО ВРЕМЯ загрузки

        const second = new XMLHttpRequest();
        const secondData = new FormData();
        const secondSpy = jest.spyOn(secondData, 'append');
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(secondData);

        expect(firstSpy).toHaveBeenCalledWith('plathix_folder', '7');
        expect(secondSpy).toHaveBeenCalledWith('plathix_folder', '7');
        expect(secondSpy).not.toHaveBeenCalledWith('plathix_folder', '99');
    });

    it('keeps one locked target across a multi-file batch ([internal])', () => {
        // Batch из двух файлов: лок ставится на первом send и переживает весь batch,
        // даже если между файлами меняется openId.
        store.openId = 7;
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        const firstData = new FormData();
        const firstSpy = jest.spyOn(firstData, 'append');
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(firstData);

        // openId меняется между файлами batch
        store.openId = 99;

        const second = new XMLHttpRequest();
        const secondData = new FormData();
        const secondSpy = jest.spyOn(secondData, 'append');
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(secondData);

        expect(firstSpy).toHaveBeenCalledWith('plathix_folder', '7');
        expect(secondSpy).toHaveBeenCalledWith('plathix_folder', '7');
        expect(secondSpy).not.toHaveBeenCalledWith('plathix_folder', '99');
    });

    it('does not finish the session until the last active upload completes', () => {
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(new FormData());

        const second = new XMLHttpRequest();
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(new FormData());

        expect(store.activeUploadCount).toBe(2);
        expect(store.isUploading).toBe(true);

        first.status = 200;
        first.trigger('loadend');
        queue.trigger('reset');

        expect(store.activeUploadCount).toBe(1);
        expect(store.applyFolderFilter).not.toHaveBeenCalled();

        second.status = 200;
        second.trigger('loadend');
        queue.trigger('reset');

        expect(store.activeUploadCount).toBe(0);
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
    });

    it('refreshes folders once when the upload session ends, not on every file', () => {
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(new FormData());

        const second = new XMLHttpRequest();
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(new FormData());

        first.status = 200;
        first.trigger('loadend');
        expect(store.refreshFolders).not.toHaveBeenCalled();

        second.status = 200;
        second.trigger('loadend');
        expect(store.refreshFolders).not.toHaveBeenCalled();

        queue.trigger('reset');

        expect(store.refreshFolders).toHaveBeenCalledTimes(1);
        expect(store.refreshFolders).toHaveBeenCalledWith({ silent: true });
    });

    it('[internal] (progressive): first file in a quiet batch refreshes immediately (leading edge)', () => {
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(new FormData());

        first.status = 200;
        first.trigger('loadend');
        // Первый файл в тихом окне виден сразу — debounce leading edge ([internal] fix
        // does not delay the single/first-file case).
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);
    });

    it('[internal] (reopened): rapid successive files coalesce into one debounced refresh, not one per file', () => {
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        first.open('POST', '/wp-admin/async-upload.php');
        first.send(new FormData());

        const second = new XMLHttpRequest();
        second.open('POST', '/wp-admin/async-upload.php');
        second.send(new FormData());

        first.status = 200;
        first.trigger('loadend');
        // Leading edge — первый файл виден сразу.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);

        second.status = 200;
        second.trigger('loadend');
        // [internal] [internal]: раньше второй файл СРАЗУ (синхронно) вызывал ещё один
        // refreshMediaFrame() — на большой библиотеке это создавало гонку N параллельных
        // _requery(), каждый заново обнулял грид в []. Теперь второй файл внутри debounce
        // window (300ms) не даёт немедленного повторного вызова.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);

        jest.advanceTimersByTime(300);
        // После тишины — один догоняющий вызов коалесцирует оба файла.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(2);

        queue.trigger('reset');
        // endSession добавляет один финальный safety-net вызов.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(3);
    });

    it('[internal] (reopened): continuous stream of files still gets a refresh within maxWait', () => {
        bindUploadCompleteEvents();

        const uploadOne = () => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(new FormData());
            xhr.status = 200;
            xhr.trigger('loadend');
        };

        uploadOne();
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);

        // Плотный поток файлов, каждый успевает загрузиться до истечения debounce-окна —
        // без maxWait это откладывало бы refresh бесконечно долго. 4 шага по 200ms — суммарно
        // 800ms, строго меньше maxWait (1000ms), чтобы не задеть его границу в этом цикле.
        for (let i = 0; i < 4; i++) {
            jest.advanceTimersByTime(200);
            uploadOne();
        }
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(1);

        jest.advanceTimersByTime(1000);
        // maxWait гарантирует финальный вызов независимо от того, что поток файлов
        // продолжается.
        expect(store.refreshMediaFrame).toHaveBeenCalledTimes(2);
    });

    it('returns to the locked folder with resetPage when the user navigated away during upload', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(new FormData());

        store.openId = 12;
        xhr.status = 200;
        xhr.trigger('loadend');
        queue.trigger('reset');

        expect(store.openId).toBe(7);
        expect(store.applyFolderFilter).toHaveBeenCalledWith(7, { resetPage: true });
    });

    it('ends the session after upload errors too', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(new FormData());
        xhr.trigger('loadend');

        jest.advanceTimersByTime(2600);

        expect(store.isUploading).toBe(false);
        expect(store.activeUploadCount).toBe(0);
        expect(store.dismissNotificationByKey).toHaveBeenCalledWith('upload-session');
        expect(store.applyFolderFilter).not.toHaveBeenCalled();
    });

    // [internal]: xhr.abort() / XHR timeout не эмитят 'load' ни 'error' — только реальный
    // браузерный 'loadend' покрывает эти исходы. FakeXHR не различает abort/timeout от
    // обычного error на уровне event type (оба реально были бы 'loadend' в браузере), но
    // тест доказывает сам инвариант: единственное событие, реально диспатченное браузером
    // на этих путях ('loadend', без предшествующего 'load'/'error'), декрементирует счётчик.
    it('[internal]: decrements _activeUploads on loadend even when no load/error preceded it (abort/timeout path)', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        xhr.send(new FormData());

        expect(store.activeUploadCount).toBe(1);
        expect(store.isUploading).toBe(true);

        // Ни 'load', ни 'error' не триггерятся — только 'loadend', как это происходит в
        // браузере на abort()/timeout. xhr.status остаётся 0 (дефолт FakeXHR).
        xhr.trigger('loadend');

        expect(store.activeUploadCount).toBe(0);
        expect(store.isUploading).toBe(false);

        jest.advanceTimersByTime(2600);
        expect(store.dismissNotificationByKey).toHaveBeenCalledWith('upload-session');
    });

    it('[internal]: second leak path — an XHR opened for async-upload.php but never sent still decrements on loadend', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/wp-admin/async-upload.php');
        // Инкремент происходит уже здесь (в open, [internal] второй путь утечки) — send()
        // намеренно не вызывается, эмулируя XHR, открытый и брошенный/отменённый до send.
        expect(store.activeUploadCount).toBe(1);

        xhr.trigger('loadend');

        expect(store.activeUploadCount).toBe(0);
        expect(store.isUploading).toBe(false);
    });

    it('does not patch or append folder data for non-upload requests', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        const formData = new FormData();
        const appendSpy = jest.spyOn(formData, 'append');

        xhr.open('POST', '/wp-admin/admin-ajax.php?action=unrelated');
        xhr.send(formData);
        xhr.status = 200;
        xhr.trigger('loadend');

        expect(appendSpy).not.toHaveBeenCalledWith('plathix_folder', expect.any(String));
        expect(store.isUploading).toBe(false);
        expect(store.activeUploadCount).toBe(0);
        expect(store.notify).not.toHaveBeenCalledWith('success', 'File uploaded');
    });

    it('binds upload hooks only once when initialized repeatedly', () => {
        bindUploadCompleteEvents();
        bindUploadCompleteEvents();

        expect(queue.on).toHaveBeenCalledTimes(2);
        expect(queue.on).toHaveBeenNthCalledWith(1, 'add', expect.any(Function));
        expect(queue.on).toHaveBeenNthCalledWith(2, 'reset', expect.any(Function));
        expect(onMediaFrameReady).toHaveBeenCalledTimes(1);
    });

    it('appends plathix_folder for admin-ajax upload-attachment when openId > 0', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        const formData = new FormData();
        formData.append('action', 'upload-attachment');
        const appendSpy = jest.spyOn(formData, 'append');

        xhr.open('POST', '/wp-admin/admin-ajax.php?action=upload-attachment');
        xhr.send(formData);

        expect(appendSpy).toHaveBeenCalledWith('plathix_folder', '7');
    });

    it('does not append plathix_folder for admin-ajax with unrelated action', () => {
        bindUploadCompleteEvents();

        const xhr = new XMLHttpRequest();
        const formData = new FormData();
        formData.append('action', 'some-other-action');
        const appendSpy = jest.spyOn(formData, 'append');

        xhr.open('POST', '/wp-admin/admin-ajax.php?action=some-other-action');
        xhr.send(formData);

        expect(appendSpy).not.toHaveBeenCalledWith('plathix_folder', expect.any(String));
        expect(store.isUploading).toBe(false);
    });

    it('uses _uploadLockedFolder on second ajax upload without re-reading openId', () => {
        bindUploadCompleteEvents();

        const first = new XMLHttpRequest();
        const fd1 = new FormData();
        fd1.append('action', 'upload-attachment');
        first.open('POST', '/wp-admin/admin-ajax.php?action=upload-attachment');
        first.send(fd1);

        // openId изменился после первой загрузки — второй upload должен использовать locked folder
        store.openId = 99;

        const second = new XMLHttpRequest();
        const fd2 = new FormData();
        fd2.append('action', 'upload-attachment');
        const appendSpy = jest.spyOn(fd2, 'append');
        second.open('POST', '/wp-admin/admin-ajax.php?action=upload-attachment');
        second.send(fd2);

        expect(appendSpy).toHaveBeenCalledWith('plathix_folder', '7');
    });

    describe('[internal]: блокировка отправки при открытой «Корзине»', () => {
        afterEach(() => {
            delete window.Plathix;
        });

        it('не вызывает реальную отправку (proto.send), когда target — Корзина', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 655;
            bindUploadCompleteEvents();

            const xhr = new XMLHttpRequest();
            const formData = new FormData();
            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(formData);

            // baseFakeSend (реальная "отправка" в FakeXHR) устанавливает this._data —
            // если бы отправка прошла, xhr._data было бы формой. При блокировке — не
            // установлено вовсе, т.к. proto[SEND_KEY].apply() не вызывался.
            expect(xhr._data).toBeUndefined();
        });

        it('диспатчит synthetic loadend-событие, чтобы не зависнуть в очереди ([internal]: единственное событие, на которое подписан обработчик после перехода с load/error на loadend)', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 655;
            bindUploadCompleteEvents();

            const xhr = new XMLHttpRequest();
            const loadendHandler = jest.fn();
            const originalAddEventListener = xhr.addEventListener.bind(xhr);
            xhr.addEventListener = (type, handler) => {
                originalAddEventListener(type, handler);
                if (type === 'loadend') {
                    xhr.listeners.loadend = function (...args) {
                        loadendHandler(...args);
                        handler.call(this, ...args);
                    };
                }
            };

            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(new FormData());

            expect(loadendHandler).toHaveBeenCalledTimes(1);
        });

        it('сессия корректно завершается после блокировки — _activeUploads не остаётся зависшим (WP QA skeptic finding; [internal]: теперь через loadend, не error)', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 655;
            bindUploadCompleteEvents();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(new FormData());

            // loadend-listener из proto.open ([internal]) слушает synthetic loadend
            // (диспатченный trash-guard'ом) и уменьшает _activeUploads — session state
            // не должен остаться "висящим".
            expect(store.activeUploadCount).toBe(0);
            expect(store.isUploading).toBe(false);
        });

        it('показывает notice о блокировке через store.notify', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 655;
            bindUploadCompleteEvents();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(new FormData());

            expect(store.notify).toHaveBeenCalledWith(
                'error',
                expect.stringContaining('active media library')
            );
        });

        it('regression: обычная (не-Корзина) папка по-прежнему отправляет запрос нормально', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 7;
            bindUploadCompleteEvents();

            const xhr = new XMLHttpRequest();
            const sendSpy = jest.spyOn(FakeXHR.prototype, 'send');
            const formData = new FormData();
            const appendSpy = jest.spyOn(formData, 'append');
            xhr.open('POST', '/wp-admin/async-upload.php');
            xhr.send(formData);

            expect(sendSpy).toHaveBeenCalled();
            expect(appendSpy).toHaveBeenCalledWith('plathix_folder', '7');
        });

        it('multi-file batch: второй файл в очереди тоже блокируется, не только первый', () => {
            window.Plathix = { trashFolderId: 655 };
            store.openId = 655;
            bindUploadCompleteEvents();

            const first = new XMLHttpRequest();
            first.open('POST', '/wp-admin/async-upload.php');
            first.send(new FormData());

            const second = new XMLHttpRequest();
            second.open('POST', '/wp-admin/async-upload.php');
            second.send(new FormData());

            expect(first._data).toBeUndefined();
            expect(second._data).toBeUndefined();
            expect(store.activeUploadCount).toBe(0);
        });
    });
});
