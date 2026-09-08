import { SEL_LIST, SEL_TABLENAV_TOP, SEL_TABLENAV_BOTTOM, SEL_VIEWS } from './selectors.js';

export function extractZones(doc) {
    return {
        list:           doc.querySelector(SEL_LIST),
        tablenavTop:    doc.querySelector(SEL_TABLENAV_TOP),
        tablenavBottom: doc.querySelector(SEL_TABLENAV_BOTTOM),
        views:          doc.querySelector(SEL_VIEWS),
    };
}

export function safeReplace(live, incoming) {
    if (!incoming || !live?.parentNode) return false;
    live.parentNode.replaceChild(incoming, live);
    return true;
}

export function parseFragment(html) {
    const t = document.createElement('template');
    t.innerHTML = html;
    return t.content.firstElementChild || null;
}



export function parseFragmentBySelector(html, selector) {
    const t = document.createElement('template');
    t.innerHTML = html;
    return t.content.querySelector(selector) || null;
}
