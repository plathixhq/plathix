import { restRequest } from '../sidebar/api/transport.js';
import { collectAncestorIds, buildFolderTreeIndex } from '../sidebar/store/folder-tree-utils.js';
import { getMediaFrame } from '../sidebar/runtime.js';
import { escapeHtml } from '../sidebar/utils/escape.js';
import { t } from '../sidebar/i18n.js';





function refreshMediaGrid() {
    const mediaFrame = getMediaFrame();
    if (!mediaFrame) {
        return;
    }

    try {
        const content = mediaFrame?.content?.get?.();
        const collection = content?.collection;
        const target = collection?.props ? collection : mediaFrame?.state?.()?.get?.('library');
        if (!target?.props) {
            return;
        }

        const currentFolder = target.props.get?.('plathix_folder');
        target.props.unset?.('plathix_folder', { silent: true });
        target.props.set?.({ plathix_folder: currentFolder });
        target.fetch({ reset: true });
    } catch (error) {

    }
}



function refreshFolderCounts() {
    try {
        window.Alpine?.store?.('plathix')?.refreshFolders?.({ silent: true })?.catch?.(() => {});
    } catch (error) {

    }
}

const cfg = window.PlathixFolderSwitch || {};

let cachedFolders = null;
let cachedFoldersPromise = null;


export function __resetFolderSwitchCacheForTests() {
    cachedFolders = null;
    cachedFoldersPromise = null;
}

function notify(type, message) {
    const store = window.Alpine?.store?.('plathix');
    if (store?.notify) {
        store.notify(type, message);
        return;
    }

    const container = document.querySelector('.wrap') || document.body;
    const notice = document.createElement('div');
    notice.className = `notice notice-${type === 'warning' ? 'warning' : type === 'error' ? 'error' : 'success'} is-dismissible`;
    notice.innerHTML = `<p>${escapeHtml(message)}</p>`;
    container.prepend(notice);
}



async function fetchFolders() {
    if (cachedFolders) {
        return cachedFolders;
    }
    if (cachedFoldersPromise) {
        return cachedFoldersPromise;
    }





    cachedFoldersPromise = (async () => {
        const json = await restRequest('folders', {
            method: 'GET',
            runtimeOverride: { restUrl: cfg.restUrl || '', restUrlFallback: cfg.restUrlFallback, restNonce: cfg.restNonce },
        });
        cachedFolders = Array.isArray(json?.folders) ? json.folders : [];
        return cachedFolders;
    })();

    try {
        return await cachedFoldersPromise;
    } finally {
        cachedFoldersPromise = null;
    }
}

function renderTreeRow(folder, depth, currentFolderId, byParent, onSelect) {
    const hasChildren = Boolean(folder.hasChildren);
    const isCurrent = Number(folder.id) === Number(currentFolderId);
    const indent = 6 + depth * 15;

    const row = document.createElement('div');
    row.className = 'plathix-folder-switch__tree-row' + (isCurrent ? ' is-current' : '') + (hasChildren ? ' is-expanded' : '');
    row.style.paddingLeft = `${indent}px`;
    row.dataset.folderId = String(folder.id);

    row.addEventListener('click', (event) => {


        onSelect(folder);
    });

    row.innerHTML = `
        <span class="plathix-folder-switch__tree-toggle">${hasChildren ? '<span class="plathix-folder-switch__tree-chevron"></span>' : ''}</span>
        <span class="plathix-folder-switch__tree-icon" aria-hidden="true"></span>
        <span class="plathix-folder-switch__tree-name">${escapeHtml(folder.name)}</span>
        <span class="plathix-folder-switch__tree-check" aria-hidden="true"></span>
    `;

    const wrapper = document.createElement('div');
    wrapper.appendChild(row);

    if (hasChildren) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'plathix-folder-switch__tree-children';
        const children = byParent.get(Number(folder.id)) || [];
        children.forEach((child) => {
            childrenContainer.appendChild(renderTreeRow(child, depth + 1, currentFolderId, byParent, onSelect));
        });
        wrapper.appendChild(childrenContainer);



        // .is-expanded + .plathix-folder-switch__tree-children (attachment-fields.css),

        row.querySelector('.plathix-folder-switch__tree-toggle').addEventListener('click', (event) => {
            event.stopPropagation();
            row.classList.toggle('is-expanded');
        });
    }

    return wrapper;
}

function renderTree(popover, folders, currentFolderId, onSelect) {
    const byParent = buildFolderTreeIndex(folders);
    const roots = byParent.get(0) || [];

    const treeEl = popover.querySelector('.plathix-folder-switch__tree');
    treeEl.innerHTML = '';
    roots.forEach((folder) => {
        treeEl.appendChild(renderTreeRow(folder, 0, currentFolderId, byParent, onSelect));
    });
}


function buildBreadcrumb(folders, folderId) {
    const byId = new Map(folders.map((f) => [Number(f.id), f]));
    const ancestorIds = collectAncestorIds(folders, folderId);
    const names = ancestorIds.map((id) => byId.get(id)?.name).filter(Boolean);
    const current = byId.get(Number(folderId));
    if (current) {
        names.push(current.name);
    }
    return names.join(' / ');
}



function updateGotoZone(field, folder, folders) {
    const breadcrumb = buildBreadcrumb(folders, folder.id);
    const url = new URL(window.location.origin + '/wp-admin/upload.php');
    url.searchParams.set('mode', 'grid');
    url.searchParams.set('plathix_folder', String(folder.id));

    const existingGoto = field.querySelector('.plathix-folder-switch__goto');
    const existingEmpty = field.querySelector('.plathix-folder-switch__empty');
    const nameEl = existingGoto?.querySelector('.plathix-folder-switch__name');

    if (existingGoto && nameEl) {
        existingGoto.setAttribute('href', url.toString());
        nameEl.textContent = breadcrumb;
        return;
    }



    const goto = document.createElement('a');
    goto.className = 'plathix-folder-switch__goto';
    goto.target = '_top';
    goto.href = url.toString();
    goto.innerHTML = `
        <span class="plathix-folder-switch__icon" aria-hidden="true"></span>
        <span class="plathix-folder-switch__name">${escapeHtml(breadcrumb)}</span>
        <span class="plathix-folder-switch__extlink" aria-hidden="true"></span>
    `;
    existingEmpty?.replaceWith(goto);
}



async function selectFolder(field, folder, folders) {
    const attachmentId = Number(field.dataset.attachmentId || 0);
    const currentFolderId = Number(field.dataset.currentFolderId || 0);

    if (Number(folder.id) === currentFolderId) {
        closePopover(field);
        return;
    }




    // (MediaController::unassign_items → FolderAssignmentService::unassign_items,

    const isRoot = Number(folder.id) === 0;
    const path = isRoot ? 'items' : `folders/${folder.id}/items`;
    const method = isRoot ? 'DELETE' : 'PUT';
    const data = isRoot
        ? { item_ids: [attachmentId], post_type: 'attachment' }
        : { ids: [attachmentId], post_type: 'attachment' };

    try {





        await restRequest(path, {
            method,
            data,
            runtimeOverride: { restUrl: cfg.restUrl || '', restUrlFallback: cfg.restUrlFallback, restNonce: cfg.restNonce },
        });







        const uncategorizedTermId = Number(window.PlathixFolderSwitch?.uncategorizedTermId || 0);
        const resolvedFolderId = isRoot ? uncategorizedTermId : Number(folder.id);
        const resolvedFolder = isRoot
            ? (folders.find((f) => Number(f.id) === resolvedFolderId) || folder)
            : folder;

        field.dataset.currentFolderId = String(resolvedFolderId);
        updateGotoZone(field, resolvedFolder, folders);
        closePopover(field);
        refreshMediaGrid();
        refreshFolderCounts();
        notify('success', t('folder_switch_moved', 'Folder changed.'));
    } catch (error) {
        notify('error', error?.message || t('folder_switch_move_failed', 'Failed to move file.'));
    }
}







const openPopovers = new WeakMap();

function buildPopover() {
    const popover = document.createElement('div');
    popover.className = 'plathix-folder-switch__popover';
    popover.innerHTML = `
        <p class="plathix-folder-switch__popover-title">${escapeHtml(t('folder_switch_move_to', 'Move to folder'))}</p>
        <div class="plathix-folder-switch__tree"></div>
    `;
    return popover;
}



function positionPopover(field, popover) {
    const rect = field.getBoundingClientRect();
    const estimatedPopoverHeight = 270;
    const spaceBelow = window.innerHeight - rect.bottom;
    const showAbove = spaceBelow < estimatedPopoverHeight && rect.top > estimatedPopoverHeight;

    const titleHeight = popover.querySelector('.plathix-folder-switch__popover-title')?.offsetHeight || 32;
    const availableHeight = showAbove
        ? Math.max(rect.top - 16, 120)
        : Math.max(window.innerHeight - rect.bottom - 16, 120);





    popover.style.left = `${rect.left}px`;
    if (showAbove) {
        popover.style.bottom = `${window.innerHeight - rect.top + 6}px`;
        popover.style.top = 'auto';
    } else {
        popover.style.top = `${rect.bottom + 6}px`;
        popover.style.bottom = 'auto';
    }

    const treeEl = popover.querySelector('.plathix-folder-switch__tree');
    if (treeEl) {
        treeEl.style.maxHeight = `${Math.max(availableHeight - titleHeight - 12, 80)}px`;
    }
}



async function openPopover(field, trigger) {
    if (openPopovers.has(field)) {
        closePopover(field);
        return;
    }

    const popover = buildPopover();
    document.body.appendChild(popover);
    positionPopover(field, popover);
    openPopovers.set(field, popover);
    field.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');



    requestAnimationFrame(() => {
        popover.classList.add('is-visible');
    });

    try {
        const folders = await fetchFolders();
        const currentFolderId = Number(field.dataset.currentFolderId || 0);
        renderTree(popover, folders, currentFolderId, (folder) => selectFolder(field, folder, folders));
    } catch (error) {
        notify('error', error?.message || t('folder_switch_load_failed', 'Failed to load folders.'));
        closePopover(field);
    }
}

function closePopover(field) {
    const popover = openPopovers.get(field);
    if (!popover) {
        return;
    }
    popover.remove();
    openPopovers.delete(field);
    field.classList.remove('is-open');
    field.querySelector('.plathix-folder-switch__trigger')?.setAttribute('aria-expanded', 'false');
}

let outsideClickHandler = null;

function bindOutsideClick() {
    if (outsideClickHandler) {
        document.removeEventListener('click', outsideClickHandler, true);
    }
    outsideClickHandler = (event) => {
        document.querySelectorAll('.plathix-folder-switch__field.is-open').forEach((field) => {
            const popover = openPopovers.get(field);
            const insideField = field.contains(event.target);
            const insidePopover = popover ? popover.contains(event.target) : false;
            if (!insideField && !insidePopover) {
                closePopover(field);
            }
        });
    };
    document.addEventListener('click', outsideClickHandler, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.plathix-folder-switch__field.is-open').forEach((field) => closePopover(field));
    });
}

export function bindFolderSwitchUi() {
    if (document.body.dataset.plathixFolderSwitchUiBound === '1') {
        return;
    }
    document.body.dataset.plathixFolderSwitchUiBound = '1';

    bindOutsideClick();

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('.plathix-folder-switch__trigger') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const field = trigger.closest('.plathix-folder-switch__field');
        if (field) {
            openPopover(field, trigger);
        }
    });
}
