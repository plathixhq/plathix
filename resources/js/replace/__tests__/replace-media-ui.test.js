import { bindReplaceMediaUi } from '../replace-media-ui.js';
import { uploadMultipart } from '../../sidebar/api/transport.js';

jest.mock('../../sidebar/api/transport.js', () => ({ uploadMultipart: jest.fn() }));

const mockAlpineStore = jest.fn();
const mockUploadMultipart = /** @type {jest.Mock} */ (uploadMultipart);
const flushAsyncUi = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('replace media ui', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        document.body.innerHTML = '';
        window.Alpine = { store: mockAlpineStore };
        // bindReplaceMediaUi вешает click/change листенеры на document (не на body) и
        // раньше каждый it() снимал guard (plathixReplaceUiBound) и звал bind заново —
        // это копило дубликаты document-level листенеров между тестами (были безобидны,
        // пока побочные эффекты были идемпотентны; [internal] добавил restore текста
        // кнопки, который дублирование листенеров ломает). Биндим один раз на файл.
        bindReplaceMediaUi();
    });

    it('opens hidden file input when trigger is clicked', () => {
        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = document.querySelector('.plathix-replace__file-input');
        input.click = jest.fn();

        document.querySelector('.plathix-replace__file-trigger').click();

        expect(input.click).toHaveBeenCalledTimes(1);
    });

    it('shows warning notice and updates image source on partial success', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });

        document.body.innerHTML = `
            <div class="attachment" data-id="8"><img src="http://example.test/old.jpg"></div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8,
                url: 'http://example.test/new.jpg',
                version: 777,
                warnings: ['cleanup failed'],
                partialSuccess: true,
                newFile: 'new.jpg',
                newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(mockUploadMultipart).toHaveBeenCalledWith(
            'attachments/8/replace',
            file,
            expect.objectContaining({ includePostType: false })
        );
        expect(document.querySelector('.attachment img').getAttribute('src')).toContain('new.jpg');
        expect(document.querySelector('.attachment img').getAttribute('src')).toContain('v=777');
        // [internal]: partialSuccess-warning не должен исчезать по общему 6s-таймеру —
        // duration:0 отключает auto-dismiss в _armDismiss() (notifications.js)
        expect(notify).toHaveBeenCalledWith(
            'warning',
            expect.stringContaining('cleanup failed'),
            { duration: 0 }
        );
    });

    // [internal] ([internal]): большое превью открытой модалки обновляется после замены
    it('updates the large attachment-details preview in the open modal', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="attachment" data-id="8"><img src="http://example.test/old.jpg"></div>
            <div class="media-modal">
                <img class="details-image" src="http://example.test/old.jpg" srcset="http://example.test/old-300.jpg 300w, http://example.test/old-600.jpg 600w">
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8,
                url: 'http://example.test/new.jpg',
                version: 777,
                newFile: 'new.jpg',
                newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        const preview = document.querySelector('.media-modal img.details-image');
        // большое превью показывает новый файл с cache-bust версией
        expect(preview.getAttribute('src')).toContain('new.jpg');
        expect(preview.getAttribute('src')).toContain('v=777');
        // srcset снят безусловно — иначе браузер взял бы старую версию из srcset
        expect(preview.hasAttribute('srcset')).toBe(false);
    });

    // [internal] ([internal], повторное открытие): классический полноэкранный
    // edit-attachment экран (post.php?action=edit) — большое превью там img.thumbnail
    // внутри #media-head-{id}, не покрыт ни .attachment[data-id], ни .details-image.
    it('updates the fullpage edit-attachment preview (img.thumbnail in #media-head-{id})', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="wp_attachment_image" id="media-head-8">
                <img class="thumbnail" src="http://example.test/old.jpg" srcset="http://example.test/old-300.jpg 300w">
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8,
                url: 'http://example.test/new.jpg',
                version: 777,
                newFile: 'new.jpg',
                newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        const preview = document.querySelector('#media-head-8 img.thumbnail');
        expect(preview.getAttribute('src')).toContain('new.jpg');
        expect(preview.getAttribute('src')).toContain('v=777');
        expect(preview.hasAttribute('srcset')).toBe(false);
    });

    it('updates details-image even when it has no srcset (no-op removeAttribute)', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="media-modal">
                <img class="details-image" src="http://example.test/old.jpg">
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8, url: 'http://example.test/new.jpg', version: 888,
                newFile: 'new.jpg', newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        const preview = document.querySelector('.media-modal img.details-image');
        expect(preview.getAttribute('src')).toContain('new.jpg');
        expect(preview.getAttribute('src')).toContain('v=888');
    });

    // [internal]: панель метаданных .attachment-info не реактивна к model.trigger('change')
    // (core Attachment.Details ставит rerenderOnModelChange: false) — патчим DOM напрямую.
    it('patches attachment-info metadata panel (filename, type, size, dimensions) after replace', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="media-modal">
                <img class="details-image" src="http://example.test/old.jpg">
                <div class="attachment-info">
                    <div class="filename"><strong>File name:</strong> old.jpg</div>
                    <div class="file-type"><strong>File type:</strong> image/png</div>
                    <div class="file-size"><strong>File size:</strong> 1 KB</div>
                    <div class="dimensions"><strong>Dimensions:</strong> 100 by 100 pixels</div>
                </div>
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8,
                url: 'http://example.test/new.jpg',
                version: 999,
                newFile: '2026/07/new-image.jpg',
                newMime: 'image/jpeg',
                newWidth: 640,
                newHeight: 480,
                newFilesizeHuman: '9 KB',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        const panel = document.querySelector('.attachment-info');
        expect(panel.querySelector('.filename').textContent).toContain('new-image.jpg');
        expect(panel.querySelector('.file-type').textContent).toContain('image/jpeg');
        expect(panel.querySelector('.file-size').textContent).toContain('9 KB');
        expect(panel.querySelector('.dimensions').textContent).toContain('640 by 480 pixels');
        // label не потерян
        expect(panel.querySelector('.filename strong').textContent).toBe('File name:');
    });

    it('leaves attachment-info panel untouched when modal has no such panel (no-op)', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8, url: 'http://example.test/new.jpg', version: 999,
                newFile: 'new.jpg', newMime: 'image/jpeg', newWidth: 640, newHeight: 480,
                newFilesizeHuman: '9 KB',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(document.querySelector('.attachment-info')).toBeNull();
    });

    // [internal]: индикатор "Файл заменяется…" — оверлей на превью + текст кнопки
    it('shows overlay and "Replacing…" button text during replace, restores both after', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="media-modal">
                <img class="details-image" src="http://example.test/old.jpg">
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const button = document.querySelector('.plathix-replace__file-trigger');
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        let resolveUpload;
        mockUploadMultipart.mockReturnValue(new Promise((resolve) => {
            resolveUpload = resolve;
        }));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();

        // во время замены: оверлей вставлен, текст кнопки изменён
        expect(document.querySelector('.plathix-replace__overlay')).not.toBeNull();
        expect(button.textContent).toBe('Replacing…');

        // [internal]: контейнер картинки не имел inline position — класс
        // .plathix-replace__anchor должен быть добавлен (условный якорь для оверлея).
        const container = document.querySelector('.media-modal img.details-image').parentNode;
        expect(container.classList.contains('plathix-replace__anchor')).toBe(true);

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });
        await flushAsyncUi();
        await flushAsyncUi();
        await flushAsyncUi();

        // после замены: оверлей убран, исходный текст кнопки восстановлен
        expect(document.querySelector('.plathix-replace__overlay')).toBeNull();
        expect(button.textContent).toBe('Replace file');
        // [internal]: класс убран при restore (мы его добавляли — не чужой position).
        expect(container.classList.contains('plathix-replace__anchor')).toBe(false);
    });

    it('does not touch container position when it already has an inline position ([internal])', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="media-modal">
                <div style="position: absolute;">
                    <img class="details-image" src="http://example.test/old.jpg">
                </div>
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const container = document.querySelector('.media-modal img.details-image').parentNode;
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        let resolveUpload;
        mockUploadMultipart.mockReturnValue(new Promise((resolve) => {
            resolveUpload = resolve;
        }));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();

        // Чужой inline position не заменён нашим классом — оверлей позиционируется
        // относительно уже anchored родителя без вмешательства.
        expect(container.classList.contains('plathix-replace__anchor')).toBe(false);
        expect(container.style.position).toBe('absolute');

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });
        await flushAsyncUi();
        await flushAsyncUi();
        await flushAsyncUi();

        // После restore чужой position остаётся нетронутым.
        expect(container.style.position).toBe('absolute');
        expect(container.classList.contains('plathix-replace__anchor')).toBe(false);
    });

    it('does not throw when modal (and overlay) is removed from DOM during replace', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        document.body.innerHTML = `
            <div class="media-modal">
                <img class="details-image" src="http://example.test/old.jpg">
            </div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        let resolveUpload;
        mockUploadMultipart.mockReturnValue(new Promise((resolve) => {
            resolveUpload = resolve;
        }));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();

        // пользователь закрыл модалку во время await — core уничтожает весь её DOM
        document.querySelector('.media-modal').remove();

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });

        await expect(flushAsyncUi().then(flushAsyncUi)).resolves.toBeUndefined();
    });

    // [internal] ([internal]): без sizes в model.set() Gutenberg/Elementor вставляют
    // устаревший URL миниатюры из старой Backbone-модели при вставке картинки сразу после
    // Replace, без перезагрузки страницы. wp.media.attachment мокается напрямую — тесты
    // выше НЕ мокают window.wp, поэтому updateAttachmentModel() у них не выполняется.
    it('patches sizes into the wp.media Backbone attachment model after replace', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        const modelSet = jest.fn();
        const modelTrigger = jest.fn();
        const attachmentFactory = jest.fn().mockReturnValue({ set: modelSet, trigger: modelTrigger });
        window.wp = { media: { attachment: attachmentFactory } };

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        const sizes = {
            thumbnail: { url: 'http://example.test/new-150x150.jpg', width: 150, height: 150, orientation: 'landscape' },
            full: { url: 'http://example.test/new.jpg', width: 640, height: 480, orientation: 'landscape' },
        };

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8,
                url: 'http://example.test/new.jpg',
                version: 777,
                newFile: 'new.jpg',
                newMime: 'image/jpeg',
                sizes,
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(attachmentFactory).toHaveBeenCalledWith(8);
        expect(modelSet).toHaveBeenCalledWith(expect.objectContaining({ sizes }));

        delete window.wp;
    });

    // [internal]: результат без sizes (старый бэкенд/кэш) не должен бросать исключение —
    // model.set() получает пустой объект как безопасный дефолт.
    it('patches empty sizes object when replace result has no sizes field', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });

        const modelSet = jest.fn();
        const attachmentFactory = jest.fn().mockReturnValue({ set: modelSet, trigger: jest.fn() });
        window.wp = { media: { attachment: attachmentFactory } };

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8, url: 'http://example.test/new.jpg', version: 777,
                newFile: 'new.jpg', newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(modelSet).toHaveBeenCalledWith(expect.objectContaining({ sizes: {} }));

        delete window.wp;
    });

    it('shows error notice when replace fails', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="9">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="9">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="9">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.pdf', { type: 'application/pdf' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockRejectedValue(Object.assign(new Error('Locked'), { code: 'replace_locked' }));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('error', 'Locked');
    });

    // [internal]: сервер мог реально заменить файл, но вернуть нечитаемый ответ — это
    // не обычный replace_failed (write возможно прошёл), поэтому warning с явной
    // инструкцией обновить страницу, не error-текст.
    it('shows a distinct warning notice for rest_write_indeterminate instead of the generic replace_failed error', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="9">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="9">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="9">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.pdf', { type: 'application/pdf' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockRejectedValue(Object.assign(new Error('indeterminate'), { code: 'rest_write_indeterminate' }));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('warning', 'The file may have been replaced, but the server response could not be confirmed. Reload the page to check.');
        expect(notify).not.toHaveBeenCalledWith('error', expect.anything());
    });

    // [internal]: модалка открыта (.media-modal.wp-core-ui, core-класс из
    // wp-includes/media-template.php), но .details-image в ней не найден — реальный сбой
    it('shows preview-refresh-failed warning when modal is open but details-image is missing', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });

        document.body.innerHTML = `
            <div class="media-modal wp-core-ui"></div>
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8, url: 'http://example.test/new.jpg', version: 1,
                newFile: 'new.jpg', newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('success', expect.stringContaining('replaced'));
        expect(notify).toHaveBeenCalledWith('warning', expect.stringContaining('preview'));
    });

    it('does not show preview-refresh-failed warning when modal is simply not open', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockResolvedValue({
                attachmentId: 8, url: 'http://example.test/new.jpg', version: 1,
                newFile: 'new.jpg', newMime: 'image/jpeg',
            });

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('success', expect.stringContaining('replaced'));
        expect(notify).not.toHaveBeenCalledWith('warning', expect.anything());
    });
});
