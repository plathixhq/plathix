import { buildRequestUrl } from '../rest-url.js';


describe('buildRequestUrl()', () => {
    const pretty = 'https://site.tld/wp-json/plathix/v1/';
    const restRoute = 'https://site.tld/index.php?rest_route=/plathix/v1/';

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        expect(buildRequestUrl(pretty, 'media/bulk-trash')).toBe(
            'https://site.tld/wp-json/plathix/v1/media/bulk-trash',
        );
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        expect(buildRequestUrl(pretty, 'folders?post_type=attachment')).toBe(
            'https://site.tld/wp-json/plathix/v1/folders?post_type=attachment',
        );
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        expect(buildRequestUrl(restRoute, 'media/bulk-trash', true)).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/media/bulk-trash',
        );
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        const url = buildRequestUrl(restRoute, 'folders?post_type=attachment', true);
        expect(url).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/folders&post_type=attachment',
        );

        expect((url.match(/\?/g) || []).length).toBe(1);
    });

    it('keeps REST transport behavior consistent under retry and error conditions', () => {
        const url = buildRequestUrl(restRoute, 'folders/5/items?a=1&b=2', true);
        expect(url).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/folders/5/items&a=1&b=2',
        );
        expect((url.match(/\?/g) || []).length).toBe(1);
    });
});
