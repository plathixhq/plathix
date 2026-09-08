import { t } from '../i18n.js';
import { getPostType, getRuntime } from '../runtime.js';
import { buildRequestUrl } from './rest-url.js';
import { WRITE_METHODS, parseJson } from '../../lib/transport-shared.js';

/**
 * @typedef {Object} RestRuntimeOverride
 * @property {string} restUrl
 * @property {string} [restUrlFallback]
 * @property {string} [restNonce]
 * @property {string} [nonce]
 */

/**
 * @typedef {Object} RestRequestOptions
 * @property {string} [method]
 * @property {unknown} [data]
 * @property {boolean} [retry]
 * @property {AbortSignal | undefined} [signal]
 * @property {string | null} [overrideMethod]
 * @property {boolean} [useFallbackBase]
 * @property {RestRuntimeOverride} [runtimeOverride]
 */

/**
 * @typedef {Error & { code?: string | null }} PlathixRequestError
 */







async function refreshNonce() {
    const runtime = getRuntime();
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
        throw new Error(json?.data?.message || t('unable_refresh_nonce', 'Unable to refresh nonce.'));
    }

    runtime.nonce = json.data.nonce;
    if (json.data.restNonce) {
        runtime.restNonce = json.data.restNonce;
    }

    return json.data.nonce;
}

/**
 * @param {string} path
 * @param {RestRequestOptions} [requestOptions]
 * @returns {Promise<any>}
 */
export async function restRequest(path, requestOptions = {}) {
    const {
        method = 'GET',
        data = null,
        retry = true,
        signal = undefined,
        overrideMethod = null,


        useFallbackBase = false,





        runtimeOverride = null,
    } = requestOptions;
    const runtime = runtimeOverride || getRuntime();


    const effectiveRetry = runtimeOverride ? false : retry;
    /** @type {Record<string, string>} */
    const headers = {
        'X-WP-Nonce': runtime.restNonce || runtime.nonce || '',
    };

    /** @type {RequestInit} */
    const options = {
        method: overrideMethod ? 'POST' : method,
        headers,
        credentials: /** @type {RequestCredentials} */ ('same-origin'),
        signal,
    };

    if (overrideMethod) {
        headers['X-HTTP-Method-Override'] = overrideMethod;
    }

    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }

    const base = /** @type {string} */ (useFallbackBase ? (runtime.restUrlFallback || runtime.restUrl) : runtime.restUrl);
    const response = await fetch(buildRequestUrl(base, path, useFallbackBase), options);
    const json = await parseJson(response);

    if (!response.ok && json?.code === 'rest_cookie_invalid_nonce' && effectiveRetry) {
        await refreshNonce();
        return restRequest(path, { method, data, retry: false, signal, overrideMethod, useFallbackBase, runtimeOverride });
    }






    if (
        !response.ok
        && response.status === 405
        && !useFallbackBase
        && WRITE_METHODS.includes(overrideMethod || method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod, useFallbackBase: true, runtimeOverride });
    }



    if (
        !response.ok
        && response.status === 405
        && useFallbackBase
        && !overrideMethod
        && ['DELETE', 'PUT', 'PATCH'].includes(method)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod: method, useFallbackBase: true, runtimeOverride });
    }








    if (
        response.ok
        && json === null
        && !useFallbackBase
        && !WRITE_METHODS.includes(overrideMethod || method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod, useFallbackBase: true, runtimeOverride });
    }



    if (response.ok && json === null && useFallbackBase && !WRITE_METHODS.includes(overrideMethod || method)) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_read_corrupted', 'The server is corrupting REST responses (both /wp-json/ and rest_route returned invalid data). Contact your hosting.'))
        );
        error.code = 'rest_read_corrupted';
        throw error;
    }






    if (response.ok && json === null && WRITE_METHODS.includes(overrideMethod || method)) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.'))
        );
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    if (!response.ok) {


        const isBlockedWrite = response.status === 405 && WRITE_METHODS.includes(overrideMethod || method);
        const message = isBlockedWrite
            ? t('rest_write_blocked', 'The server is blocking REST write requests (both /wp-json/ and rest_route returned 405). Contact your hosting.')
            : (json?.message || t('request_failed', 'Request failed.'));
        const error = /** @type {PlathixRequestError} */ (new Error(message));
        error.code = json?.code || (isBlockedWrite ? 'rest_write_blocked' : null);
        throw error;
    }

    return json;
}

export function postType() {
    return getPostType();
}

export function buildQuery(params = {}) {
    const search = new URLSearchParams();
    search.set('post_type', postType());

    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
            if (value.length) search.set(key, value.join(','));
            return;
        }
        search.set(key, String(value));
    });

    return search.toString();
}

/**
 * @param {File} file
 * @param {AbortSignal | undefined} [signal]
 * @param {boolean} [retry]
 * @returns {Promise<any>}
 */
export async function uploadFile(file, signal = undefined, retry = true) {
    const runtime = getRuntime();
    const nonce = runtime.restNonce || runtime.nonce || '';
    const url   = runtime.wpMediaUrl || '/wp-json/wp/v2/media';

    const body = new FormData();
    body.append('file', file, file.name);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'X-WP-Nonce': nonce,
        },
        body,
    });

    const json = await parseJson(response);

    if (!response.ok && json?.code === 'rest_cookie_invalid_nonce' && retry) {
        await refreshNonce();
        return uploadFile(file, signal, false);
    }

    if (!response.ok) {
        const error = /** @type {PlathixRequestError} */ (new Error(json?.message || t('upload_failed', 'Upload failed.')));
        error.code = json?.code || null;
        throw error;
    }





    if (json === null) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.'))
        );
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    return json;
}




/**
 * @param {string} path
 * @param {File} file
 * @param {UploadMultipartOptions} [options]
 * @returns {Promise<any>}
 */
export async function uploadMultipart(path, file, options = {}) {
    const {
        signal = undefined,
        retry = true,
        useFallback = false,
        includePostType = true,
        runtimeOverride = null,
    } = options;
    const runtime = runtimeOverride || getRuntime();
    const nonce = runtime.restNonce || runtime.nonce || '';



    const effectiveRetry = runtimeOverride ? false : retry;


    const body = new FormData();
    body.append('file', file, file.name);
    if (includePostType) {
        body.append('post_type', postType());
    }

    const base = /** @type {string} */ (useFallback ? (runtime.restUrlFallback || runtime.restUrl) : runtime.restUrl);
    const response = await fetch(buildRequestUrl(base, path, useFallback), {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'X-WP-Nonce': nonce,
        },
        body,
    });

    const json = await parseJson(response);

    if (!response.ok && json?.code === 'rest_cookie_invalid_nonce' && effectiveRetry) {
        await refreshNonce();
        return uploadMultipart(path, file, { signal, retry: false, useFallback, includePostType, runtimeOverride });
    }



    if (
        !response.ok
        && response.status === 405
        && !useFallback
        && runtime.restUrlFallback
        && runtime.restUrlFallback !== runtime.restUrl
    ) {
        return uploadMultipart(path, file, { signal, retry, useFallback: true, includePostType, runtimeOverride });
    }

    if (!response.ok) {
        const isBlockedWrite = response.status === 405 && useFallback;
        const message = isBlockedWrite
            ? t('rest_write_blocked', 'The server is blocking REST write requests (both /wp-json/ and rest_route returned 405). Contact your hosting.')
            : (json?.message || t('upload_failed', 'Upload failed.'));
        const error = /** @type {PlathixRequestError} */ (new Error(message));
        error.code = json?.code || (isBlockedWrite ? 'rest_write_blocked' : null);
        throw error;
    }





    if (json === null) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.'))
        );
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    return json;
}
