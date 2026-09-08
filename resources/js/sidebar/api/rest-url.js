

export function buildRequestUrl(base, path, restRoute = false) {
    if (!restRoute) {

        return `${base}${path}`;
    }

    // rest_route: base = "https://site/index.php?rest_route=/plathix/v1/".

    const qIndex = path.indexOf('?');
    const endpointPath = qIndex === -1 ? path : path.slice(0, qIndex);
    const endpointQuery = qIndex === -1 ? '' : path.slice(qIndex + 1);


    let url = `${base}${endpointPath}`;


    if (endpointQuery) {
        url += `&${endpointQuery}`;
    }

    return url;
}
