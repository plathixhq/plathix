import { uploadMultipart } from '../sidebar/api/transport.js';
import { escapeHtml } from '../sidebar/utils/escape.js';
import { t } from '../sidebar/i18n.js';

const cfg = window.PlathixReplace || {};

async function replaceAttachment(id, file) {






    return uploadMultipart(`attachments/${id}/replace`, file, {
        includePostType: false,
        runtimeOverride: { restUrl: cfg.restUrl || '', restUrlFallback: cfg.restUrlFallback, restNonce: cfg.restNonce },
    });
}

function buildNoticeMessage(result) {
    const warnings = Array.isArray(result?.warnings) ? result.warnings.filter(Boolean).map(escapeHtml) : [];
    if (result?.partialSuccess) {
        const base = escapeHtml(t('replace_partial_success', 'File replaced, but some cleanup steps need manual review.'));
        return warnings.length ? `${base} ${warnings.join(' ')}` : base;
    }

    return escapeHtml(t('replace_success', 'File replaced successfully.'));
}

function notify(type, message, options) {
    const store = window.Alpine?.store?.('plathix');
    if (store?.notify) {







        if (options === undefined) {
            store.notify(type, message);
        } else {
            store.notify(type, message, options);
        }
        return;
    }

    const container = document.querySelector('.wrap') || document.body;
    const notice = document.createElement('div');
    notice.className = `notice notice-${type === 'warning' ? 'warning' : type === 'error' ? 'error' : 'success'} is-dismissible`;
    notice.innerHTML = `<p>${escapeHtml(message)}</p>`;
    container.prepend(notice);
}

function withVersion(url, version) {
    if (!url || !version) {
        return url;
    }

    try {
        const next = new URL(url, window.location.origin);
        next.searchParams.set('v', String(version));
        return next.toString();
    } catch {
        return `${url}${url.includes('?') ? '&' : '?'}v=${encodeURIComponent(String(version))}`;
    }
}

function updateAttachmentModel(attachmentId, result) {
    const attachmentFactory = window.wp?.media?.attachment;
    if (typeof attachmentFactory !== 'function') {
        return false;
    }

    const model = attachmentFactory(attachmentId);
    if (!model?.set) {
        return false;
    }

    const nextUrl = withVersion(result?.url || '', result?.version);
    const attrs = {
        url: nextUrl,
        icon: nextUrl,
        filename: String(result?.newFile || '').split('/').pop() || '',
        mime: result?.newMime || '',
        modified: result?.version || '',



        sizes: result?.sizes || {},
    };

    model.set(attrs);
    model.trigger?.('change', model);
    return true;
}

/**
 * @return {boolean}
 */
function patchDomForAttachment(attachmentId, result) {
    const nextUrl = withVersion(result?.url || '', result?.version);
    if (!nextUrl) {
        return false;
    }

    document.querySelectorAll(`.attachment[data-id="${attachmentId}"] img, tr#post-${attachmentId} img`).forEach((node) => {
        node.setAttribute('src', nextUrl);
    });

    document.querySelectorAll(`.plathix-replace__file-wrap[data-attachment-id="${attachmentId}"] a`).forEach((node) => {
        node.setAttribute('href', nextUrl);
    });






    const modal = document.querySelector('.media-modal.wp-core-ui');
    const previews = document.querySelectorAll('.media-modal img.details-image');
    previews.forEach((node) => {
        node.removeAttribute('srcset');
        node.setAttribute('src', nextUrl);
    });







    const fullpagePreview = document.querySelector(`#media-head-${attachmentId} img.thumbnail`);
    if (fullpagePreview) {
        fullpagePreview.removeAttribute('srcset');
        fullpagePreview.setAttribute('src', nextUrl);
    }

    patchAttachmentInfoPanel(result);




    return Boolean(modal) && previews.length === 0;
}



function patchAttachmentInfoPanel(result) {
    const panel = document.querySelector('.media-modal .attachment-info');
    if (!panel) {
        return;
    }

    const filename = String(result?.newFile || '').split('/').pop() || '';
    if (filename) {
        const node = panel.querySelector('.filename');
        if (node?.lastChild) {
            node.lastChild.textContent = ' ' + filename;
        }
    }

    if (result?.newMime) {
        const node = panel.querySelector('.file-type');
        if (node?.lastChild) {
            node.lastChild.textContent = ' ' + result.newMime;
        }
    }

    if (result?.newFilesizeHuman) {
        const node = panel.querySelector('.file-size');
        if (node?.lastChild) {
            node.lastChild.textContent = ' ' + result.newFilesizeHuman;
        }
    }

    const width = Number(result?.newWidth || 0);
    const height = Number(result?.newHeight || 0);
    if (width && height) {
        const node = panel.querySelector('.dimensions');
        if (node?.lastChild) {
            const template = t('replace_dimensions_format', '%1$s by %2$s pixels');
            node.lastChild.textContent = ' ' + template.replace('%1$s', String(width)).replace('%2$s', String(height));
        }
    }
}

/**
 * @return {boolean}
 */
function updateUiAfterReplace(attachmentId, result) {
    const usedModel = updateAttachmentModel(attachmentId, result);
    if (!usedModel) {
        return patchDomForAttachment(attachmentId, result);
    }
    return patchDomForAttachment(attachmentId, result);
}



function showReplaceOverlay() {
    const preview = document.querySelector('.media-modal img.details-image');
    if (!(preview?.parentNode instanceof HTMLElement)) {
        return null;
    }

    const container = preview.parentNode;






    const previousPosition = container.style.position;



    const addedAnchorClass = !previousPosition;
    if (addedAnchorClass) {
        container.classList.add('plathix-replace__anchor');
    }

    const containerRect = container.getBoundingClientRect();
    const previewRect = preview.getBoundingClientRect();

    const overlay = document.createElement('div');
    overlay.className = 'plathix-replace__overlay';
    overlay.dataset.plathixRestorePosition = previousPosition;
    overlay.dataset.plathixAnchorClassAdded = addedAnchorClass ? '1' : '';
    overlay.style.top = `${previewRect.top - containerRect.top}px`;
    overlay.style.left = `${previewRect.left - containerRect.left}px`;
    overlay.style.width = `${previewRect.width}px`;
    overlay.style.height = `${previewRect.height}px`;
    overlay.innerHTML = '<span class="plathix-replace__overlay-spinner"></span>';
    container.insertBefore(overlay, preview.nextSibling);
    return overlay;
}

function hideReplaceOverlay(overlay) {



    if (!overlay || !document.contains(overlay)) {
        return;
    }

    const container = overlay.parentNode;
    const anchorClassAdded = overlay.dataset.plathixAnchorClassAdded === '1';
    overlay.remove();
    if (container instanceof HTMLElement && anchorClassAdded) {



        container.classList.remove('plathix-replace__anchor');
    }
}

async function handleReplaceInput(input) {
    const attachmentId = Number(input.dataset.attachmentId || input.closest('[data-attachment-id]')?.dataset?.attachmentId || 0);
    const file = input.files?.[0];
    if (!(attachmentId > 0) || !file) {
        return;
    }

    const buttons = document.querySelectorAll(`.plathix-replace__file-trigger[data-attachment-id="${attachmentId}"]`);
    const originalLabels = new Map();
    buttons.forEach((button) => {
        originalLabels.set(button, button.textContent);
        button.setAttribute('disabled', 'disabled');
        button.textContent = t('replace_in_progress', 'Replacing…');
    });
    const overlay = showReplaceOverlay();

    try {
        const result = await replaceAttachment(attachmentId, file);
        const previewRefreshFailed = updateUiAfterReplace(attachmentId, result);
        if (result?.partialSuccess) {




            notify('warning', buildNoticeMessage(result), { duration: 0 });
        } else {
            notify('success', buildNoticeMessage(result));
        }
        if (previewRefreshFailed) {
            notify('warning', t('replace_preview_refresh_failed', 'File replaced, but the preview could not be refreshed. Reload the page to see the new file.'));
        }
    } catch (error) {



        if (error?.code === 'rest_write_indeterminate') {
            notify('warning', t('replace_write_indeterminate', 'The file may have been replaced, but the server response could not be confirmed. Reload the page to check.'));
        } else {
            notify('error', error?.message || t('replace_failed', 'Replace failed.'));
        }
    } finally {
        input.value = '';
        buttons.forEach((button) => {
            button.removeAttribute('disabled');
            button.textContent = originalLabels.get(button);
        });
        hideReplaceOverlay(overlay);
    }
}

export function bindReplaceMediaUi() {
    if (document.body.dataset.plathixReplaceUiBound === '1') {
        return;
    }

    document.body.dataset.plathixReplaceUiBound = '1';

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('.plathix-replace__file-trigger') : null;
        if (!target) {
            return;
        }

        event.preventDefault();
        const wrapper = target.closest('.plathix-replace__file-wrap');
        const input = wrapper?.querySelector('.plathix-replace__file-input');
        if (input instanceof HTMLInputElement) {
            input.click();
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.classList.contains('plathix-replace__file-input')) {
            return;
        }

        handleReplaceInput(target);
    });
}
