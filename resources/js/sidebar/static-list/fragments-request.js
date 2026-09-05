export async function fetchListFragments(params, signal) {
    const ajaxUrl = window.Plathix?.ajaxUrl
        || window.Plathix?.ajaxurl
        || window.ajaxurl
        || '/wp-admin/admin-ajax.php';

    const nonce = window.Plathix?.nonce || '';

    const body = new FormData();
    // CTAN-202: action — данные конфига; Free-дефолт обслуживает медиатеку, PRO-конфиг
    // на своих экранах задаёт свой action (свой контроллер фрагментов).
    body.append('action', String(window.Plathix?.listScreenAction || 'plathix_list_screen'));
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
