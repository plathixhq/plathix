let _popHandler = null;
let _trashUrlGuarded = false;
let _viewSwitchHrefGuarded = false;



export function guardTrashUrl(isTrashActive) {
    if (_trashUrlGuarded) {
        return;
    }
    _trashUrlGuarded = true;

    const nativeReplaceState = window.history.replaceState.bind(window.history);

    window.history.replaceState = function guardedReplaceState(state, title, url) {
        nativeReplaceState(state, title, url);

        if (!isTrashActive()) {
            return;
        }

        try {
            const current = new URL(window.location.href);
            if (current.searchParams.get('attachment-filter') !== 'trash') {
                current.searchParams.set('attachment-filter', 'trash');
                nativeReplaceState(state, title, current.toString());
            }
        } catch {

        }
    };
}



export function bindViewSwitchTrashHrefGuard(isTrashActive) {
    if (_viewSwitchHrefGuarded) {
        return;
    }
    _viewSwitchHrefGuarded = true;

    document.addEventListener('click', (event) => {
        const linkEl = event.target?.closest?.('a');
        if (!linkEl?.closest?.('.view-switch') || !(linkEl instanceof HTMLAnchorElement)) {
            return;
        }

        if (!isTrashActive()) {
            return;
        }

        try {
            const url = new URL(linkEl.href, window.location.origin);
            if (url.searchParams.get('attachment-filter') === 'trash') {
                return;
            }
            url.searchParams.set('attachment-filter', 'trash');
            linkEl.href = url.toString();
        } catch {

        }
    }, true);
}

export function pushUrl(url, state = {}) {
    history.pushState({ plathixNav: true, ...state }, '', url);
}

export function replaceUrl(url, state = {}) {
    history.replaceState({ plathixNav: true, ...state }, '', url);
}

export function onPopState(callback) {
    if (_popHandler) {
        window.removeEventListener('popstate', _popHandler);
    }
    _popHandler = (e) => callback(window.location.href, e);
    window.addEventListener('popstate', _popHandler);
}

export function removePopState() {
    if (_popHandler) {
        window.removeEventListener('popstate', _popHandler);
        _popHandler = null;
    }
}
