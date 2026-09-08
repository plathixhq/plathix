

import { Api } from '../api.js';
import { t } from '../i18n.js';
import { escapeHtml, escapeAttr } from '../utils/escape.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';


export const CONTAINER_ID = 'plathix-folder-trash-panel';



let trashedFolderIds = new Set();



const cacheListeners = new Set();

/** @param {() => void} fn */
export function onTrashedFolderIdsChange(fn) {
    cacheListeners.add(fn);
    return () => cacheListeners.delete(fn);
}

function notifyCacheListeners() {
    cacheListeners.forEach((fn) => { try { fn(); } catch (e) {} });
}

/** @returns {Set<number>} */
export function getCachedTrashedFolderIds() {
    return trashedFolderIds;
}


export async function refreshTrashedFolderIds() {
    try {
        const data = await Api.getTrashedFolders();
        const folders = Array.isArray(data?.folders) ? data.folders : [];
        trashedFolderIds = new Set(folders.map((f) => Number(f.id)).filter((id) => id > 0));
        notifyCacheListeners();
    } catch (e) {


    }
    return trashedFolderIds;
}



export async function fetchAndRenderTiles(container, store) {
    container.innerHTML = `<div class="plathix-folder-trash-panel__loading">${t('loading', 'Loading…')}</div>`;

    let folders = [];
    try {
        const data = await Api.getTrashedFolders();
        folders = Array.isArray(data?.folders) ? data.folders : [];


        trashedFolderIds = new Set(folders.map((f) => Number(f.id)).filter((id) => id > 0));
        notifyCacheListeners();
    } catch (e) {
        container.innerHTML = `<div class="plathix-folder-trash-panel__error">${t('files_restore_failed_notif', 'Could not load trashed folders')}</div>`;
        return;
    }

    renderTiles(container, folders, store);
}

export function renderTiles(container, folders, store) {
    if (folders.length === 0) {
        container.innerHTML = '';
        return;
    }

    const heading = t('trashed_folders_heading', 'Folders in Trash');
    const sectionLabel = t('folders_section', 'Folders');

    const tiles = folders.map((f) => tileHtml(f)).join('');

    container.innerHTML = `
        <div class="plathix-folder-trash-panel__head">
            <svg class="plathix-folder-trash-panel__head-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
            <span>${heading}</span><span class="plathix-folder-trash-panel__count">${folders.length}</span>
        </div>
        <div class="plathix-folder-trash-panel__section">${sectionLabel} · ${folders.length}</div>
        <div class="plathix-folder-trash-panel__grid">${tiles}</div>`;

    container.querySelectorAll('.plathix-folder-trash-panel__restore').forEach((btn) => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); onRestoreClick(btn, store, container); });
    });
    container.querySelectorAll('.plathix-folder-trash-panel__purge').forEach((btn) => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); onPurgeClick(btn, store, container); });
    });
}


export function tileHtml(f) {
    const id = Number(f.id);
    const name = escapeHtml(String(f.name || ''));
    const color = typeof f.color === 'string' && f.color !== '' ? f.color : '';
    const kids = Number(f.kids || 0);
    const ago = relativeTime(Number(f.deletedAt || 0));
    const restoreLabel = t('restore_label', 'Restore');
    const purgeLabel = t('purge_label', 'Delete permanently');

    return `<div class="plathix-folder-trash-panel__tile" data-id="${id}">
        ${kids > 0 ? `<span class="plathix-folder-trash-panel__badge">${kids}</span>` : ''}
        <span class="plathix-folder-trash-panel__icon" style="${color ? `color:${escapeAttr(color)}` : ''}">
            <svg width="34" height="30" viewBox="0 0 24 24" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
        </span>
        <span class="plathix-folder-trash-panel__tname" title="${escapeAttr(String(f.name || ''))}">${name}</span>
        <span class="plathix-folder-trash-panel__meta">${escapeHtml(ago)}</span>
        <span class="plathix-folder-trash-panel__acts">
            <button type="button" class="plathix-folder-trash-panel__restore" title="${restoreLabel}" aria-label="${restoreLabel}" data-id="${id}">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
            </button>
            <button type="button" class="plathix-folder-trash-panel__purge" title="${purgeLabel}" aria-label="${purgeLabel}" data-id="${id}">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            </button>
        </span>
    </div>`;
}


export function relativeTime(ts) {
    if (!ts) {
        return '';
    }
    const days = Math.floor((Date.now() / 1000 - ts) / 86400);
    if (days <= 0) {
        return t('deleted_today', 'deleted today');
    }
    if (days === 1) {
        return t('deleted_yesterday', 'deleted yesterday');
    }
    return t('deleted_days_ago', 'deleted %d days ago').replace('%d', String(days));
}



export async function onRestoreClick(btn, store, container) {
    const id = Number(btn.getAttribute('data-id'));
    if (!id) {
        return;
    }
    btn.disabled = true;
    try {
        await Api.restoreFolder(id);
        cacheInvalidateFolder(id);
        await store?.refreshFolders?.({ silent: true, skipCacheClear: true });
        await fetchAndRenderTiles(container, store);
    } catch (e) {
        btn.disabled = false;
        store?.notify?.('error', t('folder_restore_failed_notif', 'folder could not be restored'));
    }
}



export async function onPurgeClick(btn, store, container) {
    const id = Number(btn.getAttribute('data-id'));
    if (!id) {
        return;
    }
    if (!window.confirm(t('purge_confirm', 'Delete this folder permanently? This cannot be undone.'))) {
        return;
    }
    btn.disabled = true;
    try {
        await Api.purgeFolder(id);




        cacheInvalidateFolder(id);
        await store?.refreshFolders?.({ silent: true, skipCacheClear: true });
        await fetchAndRenderTiles(container, store);
    } catch (e) {
        btn.disabled = false;
        store?.notify?.('error', t('folder_purge_failed_notif', 'folder could not be deleted permanently'));
    }
}




export { escapeHtml, escapeAttr };
