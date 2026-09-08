

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
