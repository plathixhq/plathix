import { buildRequestUrl } from '../rest-url.js';

// [internal] (202): склейка URL для pretty vs rest_route стилей.
describe('buildRequestUrl()', () => {
    const pretty = 'https://site.tld/wp-json/plathix/v1/';
    const restRoute = 'https://site.tld/index.php?rest_route=/plathix/v1/';

    it('pretty: простая конкатенация base + path', () => {
        expect(buildRequestUrl(pretty, 'media/bulk-trash')).toBe(
            'https://site.tld/wp-json/plathix/v1/media/bulk-trash',
        );
    });

    it('pretty: path со своим query остаётся как есть (свой ?)', () => {
        expect(buildRequestUrl(pretty, 'folders?post_type=attachment')).toBe(
            'https://site.tld/wp-json/plathix/v1/folders?post_type=attachment',
        );
    });

    it('rest_route: путь без query дописывается в значение rest_route', () => {
        expect(buildRequestUrl(restRoute, 'media/bulk-trash', true)).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/media/bulk-trash',
        );
    });

    it('rest_route: query из path идёт через & (НЕ двойной ?)', () => {
        const url = buildRequestUrl(restRoute, 'folders?post_type=attachment', true);
        expect(url).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/folders&post_type=attachment',
        );
        // критично: ровно один "?" в URL, иначе сервер не распарсит rest_route
        expect((url.match(/\?/g) || []).length).toBe(1);
    });

    it('rest_route: несколько query-параметров склеены через & после первого', () => {
        const url = buildRequestUrl(restRoute, 'folders/5/items?a=1&b=2', true);
        expect(url).toBe(
            'https://site.tld/index.php?rest_route=/plathix/v1/folders/5/items&a=1&b=2',
        );
        expect((url.match(/\?/g) || []).length).toBe(1);
    });
});
