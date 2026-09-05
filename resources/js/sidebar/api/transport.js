import { t } from '../i18n.js';
import { getPostType, getRuntime } from '../runtime.js';
import { buildRequestUrl } from './rest-url.js';

/** Write-методы, которые сервер может резать на pretty /wp-json/ (нужен rest_route-fallback). */
const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

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

async function parseJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

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
        // [internal]: внутренний one-shot флаг. true → шлём на rest_route-base
        // (restUrlFallback) вместо pretty (restUrl). Ставится только рекурсией после 405.
        useFallbackBase = false,
        // [internal]: необязательный override runtime-конфига
        // для вызывающих ВНЕ sidebar-контекста (window.Plathix недоступен — например
        // folder-switch popover в attachment-модале/чужой медиатеке). Если передан, полностью
        // замещает getRuntime() для restUrl/restUrlFallback/nonce на этот вызов (и на его
        // internal-рекурсии retry/fallback — прокидывается дальше).
        runtimeOverride = null,
    } = requestOptions;
    const runtime = runtimeOverride || getRuntime();
    // refreshNonce() требует runtime.ajaxUrl и мутирует ТОЛЬКО getRuntime() (window.Plathix),
    // не runtimeOverride — nonce-refresh retry несовместим с override-контекстом, отключаем.
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

    // [internal]: 405 на pretty-пути от write-метода = сервер (nginx/LiteSpeed/
    // WAF) режет POST к /wp-json/. One-shot повтор на rest_route-base (restUrlFallback), тем же
    // методом/nonce/телом. Source-agnostic: реагируем на 405, не на конкретный сервер.
    // Комбинация с method-override: если 405 сохранится и на rest_route для DELETE/PUT/PATCH,
    // вложенный вызов ниже добавит X-HTTP-Method-Override уже на fallback-base.
    if (
        !response.ok
        && response.status === 405
        && !useFallbackBase
        && WRITE_METHODS.includes(overrideMethod || method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod, useFallbackBase: true, runtimeOverride });
    }

    // Уже на fallback-base и всё ещё 405 для DELETE/PUT/PATCH → пробуем method-override (POST),
    // тоже на fallback-base. One-shot: overrideMethod ещё не выставлен.
    if (
        !response.ok
        && response.status === 405
        && useFallbackBase
        && !overrideMethod
        && ['DELETE', 'PUT', 'PATCH'].includes(method)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod: method, useFallbackBase: true, runtimeOverride });
    }

    // [internal]: pretty-ответ ok (2xx), но тело НЕ распарсилось в JSON
    // (parseJson === null) = сервер/WAF испортил pretty-`/wp-json/` GET-ответ (на проде клиента A
    // GET folders → 200 + не-JSON, дерево исчезало). One-shot повтор на rest_route-base для
    // safe-методов. Триггер строго `json === null`: легитимный пустой результат = `{folders:[]}`
    // (парсится в объект, json !== null) → не ретраим. Write-методы сюда НЕ попадают (их
    // враждебный сервер режет 405, а не 200 — покрыто веткой выше; повтор write здесь = риск
    // двойной мутации).
    if (
        response.ok
        && json === null
        && !useFallbackBase
        && !WRITE_METHODS.includes(overrideMethod || method)
        && (runtime.restUrlFallback && runtime.restUrlFallback !== runtime.restUrl)
    ) {
        return restRequest(path, { method, data, retry, signal, overrideMethod, useFallbackBase: true, runtimeOverride });
    }

    // Уже на rest_route-base и всё ещё ok+не-JSON → честная ошибка, НЕ тихий null (иначе
    // потребитель снова получит пустое дерево молча). И pretty, и rest_route испорчены.
    if (response.ok && json === null && useFallbackBase && !WRITE_METHODS.includes(overrideMethod || method)) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_read_corrupted', 'The server is corrupting REST responses (both /wp-json/ and rest_route returned invalid data). Contact your hosting.'))
        );
        error.code = 'rest_read_corrupted';
        throw error;
    }

    // [internal] ([internal]): write-запрос получил 2xx, но тело не
    // распарсилось в JSON. Повтор write здесь запрещён (риск двойной мутации — см. ветку
    // выше), поэтому единственный честный исход — типизированный throw. Мутация могла
    // реально пройти на сервере; caller (store-слой) обязан явно reconcile'ить состояние
    // (silent GET-verify), а не трактовать это ни как успех, ни как "0 сделано".
    if (response.ok && json === null && WRITE_METHODS.includes(overrideMethod || method)) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.'))
        );
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    if (!response.ok) {
        // Честная деградация (Integrations-скептик): если И pretty, И rest_route дали не-ok —
        // внятная ошибка, не немой «request failed». 405 на fallback = сервер блокирует REST-запись.
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

    // [internal] (тот же класс, что #695/#787): upload — write-запрос; 2xx+non-JSON тело
    // означает, что сервер мог реально принять файл, но подтвердить это по ответу нельзя.
    // Повтор запрещён (риск повторной загрузки), поэтому единственный честный исход —
    // типизированный throw, не тихий null (симметрично restRequest/uploadMultipart).
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
 * @typedef {Object} UploadMultipartOptions
 * @property {AbortSignal | undefined} [signal]
 * @property {boolean} [retry]
 * @property {boolean} [useFallback]
 * @property {boolean} [includePostType] Добавить post_type в тело (sidebar-контекст).
 *   false для вызывающих вне sidebar (например Replace), где сервер не ждёт это поле.
 * @property {RestRuntimeOverride} [runtimeOverride]
 */

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
    // refreshNonce() требует runtime.ajaxUrl и мутирует ТОЛЬКО getRuntime() (window.Plathix),
    // не runtimeOverride — nonce-refresh retry несовместим с override-контекстом, отключаем
    // ([internal], симметрично restRequest:89).
    const effectiveRetry = runtimeOverride ? false : retry;
    // [internal]: FormData пересоздаётся при каждом вызове (включая retry), т.к.
    // браузеры (Firefox/Safari) могут пометить body как «disturbed» после первого fetch.
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

    // [internal]: 405 на pretty /wp-json/ = сервер режет POST. One-shot повтор
    // на rest_route-base (restUrlFallback). FormData пересоздаётся рекурсией (см. выше).
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

    // [internal] (тот же класс, что #695/#785): write+2xx+non-JSON тело — сервер мог
    // реально принять/заменить файл, но подтвердить это по ответу нельзя. Повтор upload
    // здесь запрещён (риск повторной замены файла), поэтому единственный честный исход —
    // типизированный throw, не тихий null.
    if (json === null) {
        const error = /** @type {PlathixRequestError} */ (
            new Error(t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.'))
        );
        error.code = 'rest_write_indeterminate';
        throw error;
    }

    return json;
}
