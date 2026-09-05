import { restRequest } from '../sidebar/api/transport.js';
import { collectAncestorIds } from '../sidebar/store/folder-tree-utils.js';
import { getMediaFrame } from '../sidebar/runtime.js';
import { escapeHtml } from '../sidebar/utils/escape.js';

/**
 * После успешного move — грид библиотеки (файлы в текущей открытой папке) не знает о
 * перемещении сам по себе, файл визуально остаётся до ручной перезагрузки. Тот же
 * refetch-примитив, что уже использует sidebar/store/navigation.js при смене папки
 * (getMediaFrame() безопасно возвращает null вне медиа-модалки, например на странице
 * вложения — там грида нет, обновлять нечего).
 */
/**
 * wp.media Query-коллекция (library) вычисляет свой url лениво из props и слушает
 * Backbone change-события на props, чтобы debounce-запустить внутренний refetch — прямой
 * library.fetch() без предварительного изменения props бросает "A url property or
 * function must be specified" (подтверждено живым логом на стенде). Тот же паттерн, что
 * sidebar/store/navigation.js уже использует при смене активной папки: props.set(...)
 * ПЕРЕД fetch. Здесь папка грида не меняется — просто toggle-round-trip одного и того же
 * значения plathix_folder, чтобы триггернуть смену без реальной фильтрации.
 */
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
        // best-effort refresh — сбой здесь не должен ломать уже успешный move.
    }
}

/**
 * Найдено по жалобе пользователя: счётчики файлов в сайдбаре (Alpine store 'plathix',
 * folder.count) не менялись после move через это split-control поле — потому что
 * перемещение шло напрямую через fetch(), в обход sidebar/store/items.js, единственного
 * владельца счётчиков ([internal]). Тот же тихий refetch, что уже
 * использует sidebar/attachment-events.js:53 при удалении вложения — не дублируем
 * optimistic increment/decrement логику bulk-move (там она нужна для diff/rollback при
 * массовых операциях), просто просим стор перепроверить счётчики у сервера.
 */
function refreshFolderCounts() {
    try {
        window.Alpine?.store?.('plathix')?.refreshFolders?.({ silent: true })?.catch?.(() => {});
    } catch (error) {
        // best-effort — сбой здесь не должен ломать уже успешный move.
    }
}

const cfg = window.PlathixFolderSwitch || {};

let cachedFolders = null;
let cachedFoldersPromise = null;

/** Сброс module-level кэша папок — только для тестов (изоляция между it()). */
export function __resetFolderSwitchCacheForTests() {
    cachedFolders = null;
    cachedFoldersPromise = null;
}

function t(key, fallback) {
    return window.Plathix?.i18n?.[key] ?? fallback;
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

/**
 * Плоский список папок с REST (та же форма ответа, что использует sidebar), кэшируется
 * на время жизни страницы — дерево строится на фронте из плоских id/parentId, повторные
 * открытия popover не бьют лишний раз по сети.
 */
async function fetchFolders() {
    if (cachedFolders) {
        return cachedFolders;
    }
    if (cachedFoldersPromise) {
        return cachedFoldersPromise;
    }

    // [internal]: restRequest несёт write-405-fallback И
    // read-non-JSON-fallback (оба уже решённых класса бага), folder-switch больше не
    // дублирует эту логику вручную. runtimeOverride нужен — этот модуль живёт вне
    // sidebar-контекста (window.Plathix), конфиг приходит из своего PHP-localize (cfg).
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

/** Строит дерево (children-массивы) из плоского списка {id, parentId, ...}. */
function buildTree(folders) {
    const byParent = new Map();
    folders.forEach((folder) => {
        const parentId = Number(folder.parentId || 0);
        if (!byParent.has(parentId)) {
            byParent.set(parentId, []);
        }
        byParent.get(parentId).push(folder);
    });
    return byParent;
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
        // Клик по стрелке раскрытия обрабатывается отдельным listener'ом ниже
        // (stopPropagation) — сюда долетает только клик по самой строке (выбор папки).
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

        // [internal]: раньше писал .style.display напрямую — теперь
        // .plathix-folder-switch__tree-children показывается через CSS-селектор
        // .is-expanded + .plathix-folder-switch__tree-children (attachment-fields.css),
        // reagируя на тот же classList.toggle('is-expanded'), что уже двигал chevron.
        row.querySelector('.plathix-folder-switch__tree-toggle').addEventListener('click', (event) => {
            event.stopPropagation();
            row.classList.toggle('is-expanded');
        });
    }

    return wrapper;
}

function renderTree(popover, folders, currentFolderId, onSelect) {
    const byParent = buildTree(folders);
    const roots = byParent.get(0) || [];

    const treeEl = popover.querySelector('.plathix-folder-switch__tree');
    treeEl.innerHTML = '';
    roots.forEach((folder) => {
        treeEl.appendChild(renderTreeRow(folder, 0, currentFolderId, byParent, onSelect));
    });
}

/** Строит breadcrumb "Root / Child / Name" из плоского списка (аналог PHP build_breadcrumb). */
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

/**
 * Обновляет левую зону split-control на месте после успешной смены папки — без
 * перезагрузки страницы/модалки. Заменяет placeholder "— No folder —" на ссылку, если
 * до этого у файла не было папки.
 */
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

    // Не было ссылки (был placeholder "— No folder —") — построить split-control
    // заново для левой зоны.
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

/**
 * Выбор папки в дереве: no-op если это уже текущая (просто закрыть popover), иначе —
 * REST move_items с ids:[attachmentId], обновление UI на успех, notify на ошибку
 * (popover остаётся открытым при ошибке — дать пользователю повторить).
 */
async function selectFolder(field, folder, folders) {
    const attachmentId = Number(field.dataset.attachmentId || 0);
    const currentFolderId = Number(field.dataset.currentFolderId || 0);

    if (Number(folder.id) === currentFolderId) {
        closePopover(field);
        return;
    }

    // folder.id === 0 — виртуальный корень "Медиафайлы" (не настоящий WP_Term, только
    // узел дерева для UI), PUT /folders/0/items отклоняется бэкендом с 400 (id должен
    // быть > 0). "Убрать из всех папок" — отдельный REST-ресурс DELETE /items
    // (MediaController::unassign_items → FolderAssignmentService::unassign_items,
    // wp_set_object_terms($id, [], $taxonomy)), не PUT /folders/{id}/items.
    const isRoot = Number(folder.id) === 0;
    const path = isRoot ? 'items' : `folders/${folder.id}/items`;
    const method = isRoot ? 'DELETE' : 'PUT';
    const data = isRoot
        ? { item_ids: [attachmentId], post_type: 'attachment' }
        : { ids: [attachmentId], post_type: 'attachment' };

    try {
        // [internal]: restRequest несёт write-405-fallback
        // (тот же паттерн, что уже покрывал этот код вручную) — единственный транспорт для
        // всего плагина, не дублируем условие здесь. 429 rate-limit: сервер уже отдаёт
        // осмысленный message в теле (MediaController::move_items/unassign_items), generic
        // json?.message в restRequest этого достаточно — отдельная rateLimited-ветка не нужна.
        await restRequest(path, {
            method,
            data,
            runtimeOverride: { restUrl: cfg.restUrl || '', restUrlFallback: cfg.restUrlFallback, restNonce: cfg.restNonce },
        });

        // "В Медиафайлы" физически = снять все термы = виртуально "Несортированные"
        // (тот же факт, что [internal] зафиксировал на бэкенде, FolderSwitchField::render).
        // Показываем реальное итоговое состояние, не узел "Медиафайлы", в который нельзя
        // фактически попасть как term_relationship.
        // window.PlathixFolderSwitch читается заново (не через модульный cfg) — в тестах
        // localize-объект выставляется мокально до этого вызова, но уже после импорта модуля.
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

// Popover рендерится в document.body (не потомок .plathix-folder-switch__field) — WP
// compat-fields разметка модалки оборачивает поле в несколько overflow:hidden контейнеров
// (TR.compat-field-*, FORM.compat-item, DIV.settings), которые обрезают любой absolute-
// потомок. position:fixed + пересчёт координат по field — единственный способ показать
// popover поверх модалки, не трогая нативную WP-разметку. Связь field <-> popover — через
// WeakMap (не через DOM-вложенность).
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

/**
 * Найдено adversarial UI review: popover обрезался нижним краем окна модалки
 * (media-modal-content имеет собственный overflow:auto ниже определённой высоты), список
 * папок обрывался на полуслове, перекрывал соседний блок "Заменить файл". Стандартный
 * flip-паттерн: если под полем не хватает места на popover (эвристика — высота
 * .plathix-folder-switch__tree max-height 224px + заголовок ~40px), показываем НАД полем.
 */
function positionPopover(field, popover) {
    const rect = field.getBoundingClientRect();
    const estimatedPopoverHeight = 270;
    const spaceBelow = window.innerHeight - rect.bottom;
    const showAbove = spaceBelow < estimatedPopoverHeight && rect.top > estimatedPopoverHeight;

    const titleHeight = popover.querySelector('.plathix-folder-switch__popover-title')?.offsetHeight || 32;
    const availableHeight = showAbove
        ? Math.max(rect.top - 16, 120)
        : Math.max(window.innerHeight - rect.bottom - 16, 120);

    // position:fixed — static, в CSS (.plathix-folder-switch__popover, [internal]).
    // left/top/bottom остаются inline: рантайм-вычисление из getBoundingClientRect,
    // top:'auto'/bottom:'auto' — часть того же if/else, что и px-значения того же
    // свойства (не разделяю их между JS/CSS, иначе то же затирание, что в resize.js).
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

/**
 * Открывает popover-дерево для конкретного split-control поля. Единственный открытый
 * popover за раз (per-field) — повторный клик на тот же trigger закрывает его.
 */
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

    // requestAnimationFrame — вставить с opacity:0, затем добавить is-visible на
    // следующем кадре, чтобы CSS-transition (opacity/transform) реально анимировался.
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
