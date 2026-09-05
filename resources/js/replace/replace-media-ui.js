import { uploadMultipart } from '../sidebar/api/transport.js';
import { escapeHtml } from '../sidebar/utils/escape.js';

const cfg = window.PlathixReplace || {};

function t(key, fallback) {
    return window.Plathix?.i18n?.[key] ?? fallback;
}

async function replaceAttachment(id, file) {
    // [internal]: uploadMultipart несёт write-405-fallback И
    // read-non-JSON-fallback (оба уже решённых класса бага) — Replace больше не дублирует
    // эту логику вручную. runtimeOverride нужен — этот модуль живёт вне sidebar-контекста
    // (window.Plathix), конфиг приходит из своего PHP-localize (cfg). includePostType:false —
    // сервер (ReplaceRestController) уже дефолтит post_type на 'attachment' при отсутствии
    // поля, Replace не обязан его слать.
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
        // Явный undefined третьим аргументом ведёт себя как отсутствующий для реального
        // store.notify() (деструктуризация с дефолтом видит оба случая одинаково), но
        // существующие тесты этого файла проверяют вызовы notify() через jest-мок
        // toHaveBeenCalledWith('success', msg) без третьего аргумента — jest.fn() строго
        // различает "вызван с 2 аргументами" и "вызван с 3, где третий undefined". Не
        // форвардим options, если он не был передан, чтобы не расширять поверхность вызова
        // там, где вызывающий код (error/previewRefreshFailed) не в scope [internal].
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
        // [internal]/102: без sizes Gutenberg/Elementor вставляют устаревший (возможно уже
        // удалённый AttachmentFileCleanup) URL миниатюры из старой Backbone-модели при
        // вставке картинки сразу после Replace, без перезагрузки страницы ([internal]).
        sizes: result?.sizes || {},
    };

    model.set(attrs);
    model.trigger?.('change', model);
    return true;
}

/**
 * @return {boolean} true, если модалка открыта, но превью обновить не удалось
 * ([internal]) — сигнал вызывающей стороне показать warning через notify().
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

    // [internal] ([internal]): большое превью открытой core-модалки «Информация о
    // вложении» (wp.media TwoColumn) — это <img class="details-image">. core-view не
    // перерисовывает его реактивно по смене url модели, поэтому обновляем src напрямую.
    // srcset снимаем безусловно (no-op когда его нет): иначе браузер возьмёт старую
    // версию из srcset и versioned src проглотится (defensive, QA-скептик [internal]).
    const modal = document.querySelector('.media-modal.wp-core-ui');
    const previews = document.querySelectorAll('.media-modal img.details-image');
    previews.forEach((node) => {
        node.removeAttribute('srcset');
        node.setAttribute('src', nextUrl);
    });

    // [internal] ([internal], повторное открытие): классический полноэкранный edit-
    // attachment экран (post.php?action=edit, НЕ модалка wp.media) — большое превью там
    // <img class="thumbnail"> внутри #media-head-{id}. wp.media JS не инициализирован в
    // этом legacy-шаблоне (edit-form-advanced.php), поэтому патчим DOM напрямую, как и
    // .details-image. Скоуп по #media-head-{id} (не глобальный img.thumbnail) — WP core
    // рендерит этот id с attachment ID, исключает совпадение с чужими .thumbnail на странице.
    const fullpagePreview = document.querySelector(`#media-head-${attachmentId} img.thumbnail`);
    if (fullpagePreview) {
        fullpagePreview.removeAttribute('srcset');
        fullpagePreview.setAttribute('src', nextUrl);
    }

    patchAttachmentInfoPanel(result);

    // [internal]: модалка открыта, но большое превью в ней не найдено — реальный сбой
    // обновления (не спутать с обычным случаем "модалка вообще не открыта" — тогда
    // .media-modal.wp-core-ui отсутствует и warning не нужен).
    return Boolean(modal) && previews.length === 0;
}

/**
 * Обновляет панель метаданных модалки «Информация о вложении» ([internal]): core
 * `wp.media.view.Attachment.Details` явно ставит `rerenderOnModelChange: false`
 * (media-views.js) — model.set()/trigger('change') из updateAttachmentModel панель
 * НЕ перерисовывает. Патчим текстовые узлы напрямую, как и .details-image.
 */
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

/** @return {boolean} true, если модалка открыта, но превью обновить не удалось. */
function updateUiAfterReplace(attachmentId, result) {
    const usedModel = updateAttachmentModel(attachmentId, result);
    if (!usedModel) {
        return patchDomForAttachment(attachmentId, result);
    }
    return patchDomForAttachment(attachmentId, result);
}

/**
 * Вставляет спиннер-оверлей поверх большого превью открытой модалки на время замены
 * ([internal], вариант Б+В — оба вместе, решено пользователем). Возвращает вставленный
 * узел или null, если модалки/превью нет (no-op, как и остальные DOM-патчи этого файла).
 */
function showReplaceOverlay() {
    const preview = document.querySelector('.media-modal img.details-image');
    if (!(preview?.parentNode instanceof HTMLElement)) {
        return null;
    }

    const container = preview.parentNode;
    // Родитель .details-image (.thumbnail в core-шаблоне tmpl-attachment-details-two-column)
    // не имеет заданной core-CSS высоты — она может быть больше самой картинки (картинка
    // выровнена внутри блока). inset:0 на весь родитель растягивал оверлей на этот больший
    // блок и визуально отрывал спиннер от превью (найдено adversarial-review-ui: спиннер
    // висел ПОД картинкой в пустом пространстве). Фикс: оверлей позиционируется absolute
    // строго по размерам самой картинки (getBoundingClientRect), не по границам родителя.
    const previousPosition = container.style.position;
    // [internal]: класс .plathix-replace__anchor (position:relative) применяется
    // ТОЛЬКО когда родитель раньше не имел собственного inline position — чужой
    // position от темы/плагина не перебивается ни классом, ни прежним inline.
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
    // Модалка могла быть закрыта пользователем во время await — WP core уничтожает всю
    // её DOM, включая наш оверлей. document.contains защищает от повторного удаления узла,
    // которого уже нет в дереве (не ищем модалку заново, полагаемся на этот guard).
    if (!overlay || !document.contains(overlay)) {
        return;
    }

    const container = overlay.parentNode;
    const anchorClassAdded = overlay.dataset.plathixAnchorClassAdded === '1';
    overlay.remove();
    if (container instanceof HTMLElement && anchorClassAdded) {
        // [internal]: класс убирается только если МЫ его добавили (см.
        // showReplaceOverlay) — чужой position (previousPosition непустой) никогда
        // не трогался ни классом, ни этим restore.
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
            // [internal]: partialSuccess-warning (потерянные thumbnail-размеры) не должен
            // исчезать по общему 6-секундному таймеру store.notify() — список размеров
            // может не поместиться в это окно чтения. duration:0 отключает auto-dismiss
            // в _armDismiss() (notifications.js), пользователь закрывает уведомление сам.
            notify('warning', buildNoticeMessage(result), { duration: 0 });
        } else {
            notify('success', buildNoticeMessage(result));
        }
        if (previewRefreshFailed) {
            notify('warning', t('replace_preview_refresh_failed', 'File replaced, but the preview could not be refreshed. Reload the page to see the new file.'));
        }
    } catch (error) {
        // [internal]: сервер мог реально заменить файл, но вернуть нечитаемый ответ —
        // это не обычный replace_failed (write возможно прошёл), поэтому warning с явной
        // инструкцией обновить страницу, не error-текст "Replace failed."
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
