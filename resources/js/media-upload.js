/**
 * [internal] ([internal]): media-new.php не грузит sidebar-бандл (нет дерева
 * папок, нет Alpine store) — отдельный JS-контекст. Контекст открытой папки приходит сюда
 * через query-параметр plathix_folder, который upload-link-context.js уже дописывает в
 * href кнопки "Добавить медиафайл" на upload.php перед переходом сюда. Эта страница
 * загружается один раз (полная перезагрузка при каждой навигации), поэтому параметр
 * читается один раз при старте, без реактивности/лока (в отличие от upload-events.js,
 * который живёт на upload.php с SPA-навигацией между папками).
 */
(function () {
    const folderId = new URLSearchParams(window.location.search).get('plathix_folder');
    if (!folderId) {
        return;
    }

    const proto = XMLHttpRequest.prototype;
    const originalSend = proto.send;
    const originalOpen = proto.open;

    proto.open = function (_method, url) {
        this._isPlathixWpUpload = typeof url === 'string' && url.includes('async-upload.php');
        return originalOpen.apply(this, arguments);
    };

    proto.send = function (data) {
        if (this._isPlathixWpUpload && data instanceof FormData && !data.has('plathix_folder')) {
            data.append('plathix_folder', folderId);
        }
        return originalSend.apply(this, arguments);
    };
})();
