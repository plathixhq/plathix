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


        expect(notify).toHaveBeenCalledWith(
            'warning',
            expect.stringContaining('cleanup failed'),
            { duration: 0 }
        );
    });


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

        expect(preview.getAttribute('src')).toContain('new.jpg');
        expect(preview.getAttribute('src')).toContain('v=777');

        expect(preview.hasAttribute('srcset')).toBe(false);
    });




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


        expect(document.querySelector('.plathix-replace__overlay')).not.toBeNull();
        expect(button.textContent).toBe('Replacing…');



        const container = document.querySelector('.media-modal img.details-image').parentNode;
        expect(container.classList.contains('plathix-replace__anchor')).toBe(true);

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });
        await flushAsyncUi();
        await flushAsyncUi();
        await flushAsyncUi();


        expect(document.querySelector('.plathix-replace__overlay')).toBeNull();
        expect(button.textContent).toBe('Replace file');

        expect(container.classList.contains('plathix-replace__anchor')).toBe(false);
    });

    it('falls back to the default button text when the translated string is an empty string', async() => {


        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        window.Plathix = { ...window.Plathix, i18n: { replace_in_progress: '' } };

        document.body.innerHTML = `
            <span class="plathix-replace__file-wrap" data-attachment-id="8">
                <button type="button" class="plathix-replace__file-trigger" data-attachment-id="8">Replace file</button>
                <input type="file" class="plathix-replace__file-input" data-attachment-id="8">
            </span>
        `;

        const input = /** @type {HTMLInputElement} */ (document.querySelector('.plathix-replace__file-input'));
        const button = document.querySelector('.plathix-replace__file-trigger');
        const file = new File(['x'], 'new.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        mockUploadMultipart.mockReturnValue(new Promise(() => {}));

        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushAsyncUi();

        expect(button.textContent).toBe('Replacing…');
    });

    it('covers the attachment replace flow', async() => {
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



        expect(container.classList.contains('plathix-replace__anchor')).toBe(false);
        expect(container.style.position).toBe('absolute');

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });
        await flushAsyncUi();
        await flushAsyncUi();
        await flushAsyncUi();


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


        document.querySelector('.media-modal').remove();

        resolveUpload({ attachmentId: 8, url: 'http://example.test/new.jpg', version: 1, newFile: 'new.jpg', newMime: 'image/jpeg' });

        await expect(flushAsyncUi().then(flushAsyncUi)).resolves.toBeUndefined();
    });





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
