/**
 * Публикует restRequest/postType/parseJson/refreshNonce/runtime как window.PlathixTransport
 * для PRO-потребителей через runtime WP script dependency ([internal], [internal])
 * — не импортируется, отдельный webpack entry: PRO webpack build не видит Free-исходники,
 * функция публикуется как глобал, читаемый через wp_enqueue_script-зависимость на хендл
 * plathix-transport-shared, не как модуль. Тот же паттерн, что resources/js/lib/escape-shared.js
 * ([internal]).
 *
 * Узкое REST-core ядро — НЕ полная копия sidebar/api/transport.js. Не несёт method-override
 * (X-HTTP-Method-Override для DELETE/PUT/PATCH) и runtimeOverride (override вне sidebar-
 * контекста) — ни один из трёх PRO-потребителей (zip/folder-info/folder-upload) их не
 * использует (все запросы GET/POST). sidebar/api/transport.js продолжает существовать
 * отдельно для sidebar-специфичных путей, не мигрирует на этот файл.
 */
window.PlathixTransport = window.PlathixTransport || {};

const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

function buildRequestUrl(base, path, restRoute) {
    if (!restRoute) {
        return `${base}${path}`;
    }
    const qIndex = path.indexOf('?');
    const endpointPath = qIndex === -1 ? path : path.slice(0, qIndex);
    const endpointQuery = qIndex === -1 ? '' : path.slice(qIndex + 1);
    let url = `${base}${endpointPath}`;
    if (endpointQuery) {
        url += `&${endpointQuery}`;
    }
    return url;
}

async function parseJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

async function refreshNonce() {
    const runtime = window.Plathix || {};
    const body = new URLSearchParams();
    body.set('action', 'plathix_refresh_nonce');

    const response = await fetch(runtime.ajaxUrl || runtime.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    });

    const json = await parseJson(response);
    if (!json?.success || !json?.data?.nonce) {
        throw new Error(json?.data?.message || 'Unable to refresh nonce.');
    }

    runtime.nonce = json.data.nonce;
    if (json.data.restNonce) {
        runtime.restNonce = json.data.restNonce;
    }

    return json.data.nonce;
}

/**
 * @param {string} path
 * @param {{method?: string, data?: unknown, retry?: boolean, signal?: AbortSignal, useFallbackBase?: boolean}} [requestOptions]
 * @returns {Promise<any>}
 */
async function restRequest(path, requestOptions = {}) {
    const {
        method = 'GET',
        data = null,
        retry = true,
        signal = undefined,
        useFallbackBase = false,
    } = requestOptions;
    const runtime = window.Plathix || {};

    const headers = {
        'X-WP-Nonce': runtime.restNonce || runtime.nonce || '',
    };
    const options = {
        method,
        headers,
        credentials: 'same-origin',
        signal,
    };
    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }

    const base = useFallbackBase ? (runtime.restUrlFallback || runtime.restUrl) : runtime.restUrl;
    const response = await fetch(buildRequestUrl(base, path, useFallbackBase), options);
    const json = await parseJson(response);

    // WP core rest_cookie_check_errors() всегда возвращает 403 для этого кода — строгая
    // проверка статуса (не только !response.ok) сужает триггер до точного контракта.
    if (!response.ok && response.status === 403 && json?.code === 'rest_cookie_invalid_nonce' && retry) {
        await refreshNonce();
        return restRequest(path, { method, data, retry: false, signal, useFallbackBase });
    }

    // write-405-fallback: сервер (nginx/LiteSpeed/WAF) режет write-метод на pretty /wp-json/.
    // One-shot повтор на rest_route-base, тем же методом/nonce/телом.
    if (
        !response.ok
        && response.status === 405
        && !useFallbackBase
        && WRITE_METHODS.includes(method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, useFallbackBase: true });
    }

    // read-non-JSON-fallback: pretty-ответ ok, но тело не распарсилось — сервер испортил
    // pretty-ответ. One-shot повтор на rest_route-base для safe-методов.
    if (
        response.ok
        && json === null
        && !useFallbackBase
        && !WRITE_METHODS.includes(method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, useFallbackBase: true });
    }

    if (response.ok && json === null && useFallbackBase && !WRITE_METHODS.includes(method)) {
        const error = new Error('The server is corrupting REST responses (both /wp-json/ and rest_route returned invalid data). Contact your hosting.');
        error.code = 'rest_read_corrupted';
        throw error;
    }

    if (response.ok && json === null && WRITE_METHODS.includes(method)) {
        const error = new Error('The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    if (!response.ok) {
        const isBlockedWrite = response.status === 405 && WRITE_METHODS.includes(method);
        const message = isBlockedWrite
            ? 'The server is blocking REST write requests (both /wp-json/ and rest_route returned 405). Contact your hosting.'
            : (json?.message || 'Request failed.');
        const error = new Error(message);
        error.code = json?.code || (isBlockedWrite ? 'rest_write_blocked' : null);
        throw error;
    }

    return json;
}

function postType() {
    return (window.Plathix && window.Plathix.postType) || 'attachment';
}

function runtime() {
    return window.Plathix || {};
}

window.PlathixTransport.restRequest = restRequest;
window.PlathixTransport.postType = postType;
window.PlathixTransport.parseJson = parseJson;
window.PlathixTransport.refreshNonce = refreshNonce;
window.PlathixTransport.runtime = runtime;
