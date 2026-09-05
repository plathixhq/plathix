/**
 * [internal]: построение REST-URL для двух транспортных стилей.
 *
 * pretty-permalink:  base = ".../wp-json/plathix/v1/", path = "media/bulk-trash?x=1"
 *                    → base + path (path может нести свой ?query)
 * rest_route:        base = ".../index.php?rest_route=/plathix/v1/", path = "media/bulk-trash?x=1"
 *                    → base уже содержит "?rest_route=...", поэтому query из path идёт через "&",
 *                      а сам путь дописывается в значение rest_route (до "?" в path).
 *
 * Зачем rest_route: серверы (nginx/LiteSpeed/WAF), режущие POST к красивому /wp-json/, отдают
 * 405 ДО WordPress. Стиль /index.php?rest_route= обходит pretty-permalink location и доходит до WP.
 * Оба base приходят из PHP (restUrl / restUrlFallback) — клиент не угадывает permalink-режим.
 *
 * @param {string} base   restUrl (pretty) или restUrlFallback (rest_route), оба с завершающим "/".
 * @param {string} path   путь эндпоинта относительно base, опц. со своим "?query".
 * @param {boolean} [restRoute=false] true если base — rest_route-стиль (нужна &-склейка query).
 * @returns {string}
 */
export function buildRequestUrl(base, path, restRoute = false) {
    if (!restRoute) {
        // pretty: простая конкатенация, path сам несёт свой ?query.
        return `${base}${path}`;
    }

    // rest_route: base = "https://site/index.php?rest_route=/plathix/v1/".
    // Отделяем путь эндпоинта от его query.
    const qIndex = path.indexOf('?');
    const endpointPath = qIndex === -1 ? path : path.slice(0, qIndex);
    const endpointQuery = qIndex === -1 ? '' : path.slice(qIndex + 1);

    // Путь эндпоинта дописывается в значение rest_route (base уже кончается на ".../v1/").
    let url = `${base}${endpointPath}`;

    // Доп-query эндпоинта: base уже содержит "?rest_route=...", значит только "&".
    if (endpointQuery) {
        url += `&${endpointQuery}`;
    }

    return url;
}
