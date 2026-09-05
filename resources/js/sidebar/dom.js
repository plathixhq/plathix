export const STATIC_ROOT_ID = 'plathix-sidebar-root';
export const MODAL_ROOT_ID = 'plathix-modal-sidebar-root';

export function getStaticSidebarRoot() {
    return document.getElementById(STATIC_ROOT_ID);
}

export function getModalSidebarRoot() {
    return document.getElementById(MODAL_ROOT_ID);
}

export function getSidebarRoot() {
    return getStaticSidebarRoot() || getModalSidebarRoot();
}
