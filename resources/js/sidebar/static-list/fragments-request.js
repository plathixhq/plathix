import { getRuntime } from '../runtime.js';

export async function fetchListFragments(params, signal) {
    const runtime = getRuntime();
    const ajaxUrl = runtime.ajaxUrl || runtime.ajaxurl || window.ajaxurl;

    const nonce = runtime.nonce || '';

    const body = new FormData();


    body.append('action', String(runtime.listScreenAction || 'plathix_list_screen'));
    body.append('nonce', nonce);

    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            body.append(key, String(value));
        }
    }

    const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body,
        signal,
    });

    if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
    }

    const json = await res.json();
    if (!json?.success) {
        throw new Error(json?.data?.message || 'Fragment request failed');
    }

    return json.data;
}
