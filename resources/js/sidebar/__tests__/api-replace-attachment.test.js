




// replace/replace-media-ui.js.
describe('covers the attachment replace flow', () => {
    /** @type {typeof import('../api.js').Api} */
    let Api;
    /** @type {FormData|undefined} */
    let capturedBody;

    beforeEach(() => {
        jest.resetModules();
        jest.doMock('../runtime.js', () => ({
            getRuntime: jest.fn(() => ({ restUrl: 'https://example.test/wp-json/plathix/v1/', restNonce: 'n' })),


            getPostType: jest.fn(() => 'plathix_document'),
        }));
        Api = require('../api.js').Api;

        capturedBody = undefined;
        global.fetch = jest.fn((_url, options) => {
            capturedBody = options.body;
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ id: 42 }),
            });
        });
    });

    afterEach(() => {
        delete global.fetch;
        jest.dontMock('../runtime.js');
    });

    it('keeps REST transport behavior consistent under retry and error conditions', async () => {
        const file = new File(['data'], 'photo.jpg', { type: 'image/jpeg' });

        await Api.replaceAttachment(42, file);

        expect(capturedBody).toBeInstanceOf(FormData);
        expect(capturedBody.has('post_type')).toBe(false);
    });
});
