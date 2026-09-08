

/**
 * @typedef {Error & { code?: string | null }} PlathixTransportError
 */

window.PlathixTransport = window.PlathixTransport || {};

export const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

export function buildRequestUrl(base, path, restRoute) {
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

export async function parseJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

export async function refreshNonce() {
    const runtime = /** @type {PlathixRuntime} */ (window.Plathix || {});
    const body = new URLSearchParams();
    body.set('action', 'plathix_refresh_nonce');

    const response = await fetch(runtime.ajaxUrl || runtime.ajaxurl, {
        method: 'POST',
        credentials: /** @type {RequestCredentials} */ ('same-origin'),
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
export async function restRequest(path, requestOptions = {}) {
    const {
        method = 'GET',
        data = null,
        retry = true,
        signal = undefined,
        useFallbackBase = false,
    } = requestOptions;
    const runtime = /** @type {PlathixRuntime} */ (window.Plathix || {});

    /** @type {Record<string, string>} */
    const headers = {
        'X-WP-Nonce': runtime.restNonce || runtime.nonce || '',
    };
    /** @type {RequestInit} */
    const options = {
        method,
        headers,
        credentials: /** @type {RequestCredentials} */ ('same-origin'),
        signal,
    };
    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }

    const base = useFallbackBase ? (runtime.restUrlFallback || runtime.restUrl) : runtime.restUrl;
    const response = await fetch(buildRequestUrl(base, path, useFallbackBase), options);
    const json = await parseJson(response);



    if (!response.ok && response.status === 403 && json?.code === 'rest_cookie_invalid_nonce' && retry) {
        await refreshNonce();
        return restRequest(path, { method, data, retry: false, signal, useFallbackBase });
    }



    if (
        !response.ok
        && response.status === 405
        && !useFallbackBase
        && WRITE_METHODS.includes(method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, useFallbackBase: true });
    }



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
        const error = /** @type {PlathixTransportError} */ (new Error('The server is corrupting REST responses (both /wp-json/ and rest_route returned invalid data). Contact your hosting.'));
        error.code = 'rest_read_corrupted';
        throw error;
    }

    if (response.ok && json === null && WRITE_METHODS.includes(method)) {
        const error = /** @type {PlathixTransportError} */ (new Error('The server accepted the request, but the response could not be read. Refreshing to confirm the result.'));
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    if (!response.ok) {
        const isBlockedWrite = response.status === 405 && WRITE_METHODS.includes(method);
        const message = isBlockedWrite
            ? 'The server is blocking REST write requests (both /wp-json/ and rest_route returned 405). Contact your hosting.'
            : (json?.message || 'Request failed.');
        const error = /** @type {PlathixTransportError} */ (new Error(message));
        error.code = json?.code || (isBlockedWrite ? 'rest_write_blocked' : null);
        throw error;
    }

    return json;
}

export function postType() {
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
