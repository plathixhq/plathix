export function getInternalState() {
    if (!window.__PlathixState) {
        window.__PlathixState = {};
    }

    return window.__PlathixState;
}

export function hasStateFlag(key) {
    return !!getInternalState()[key];
}

export function setStateFlag(key, value = true) {
    getInternalState()[key] = value;
}

export function getStateValue(key, fallback = null) {
    const state = getInternalState();
    return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback;
}

export function setStateValue(key, value) {
    getInternalState()[key] = value;
}
