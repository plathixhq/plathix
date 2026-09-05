import { isTrashViewFromUrl, getMediaMode, getFilterStrategy, resolveMediaFrame, getMediaFrame, getFeatures } from '../runtime.js';

describe('isTrashViewFromUrl ([internal])', () => {
    it('returns true when attachment-filter=trash is present', () => {
        expect(isTrashViewFromUrl('http://localhost/wp-admin/upload.php?attachment-filter=trash')).toBe(true);
    });

    it('returns false when attachment-filter is absent', () => {
        expect(isTrashViewFromUrl('http://localhost/wp-admin/upload.php?mode=list')).toBe(false);
    });

    it('returns false for a malformed url instead of throwing', () => {
        expect(isTrashViewFromUrl('::not a url::')).toBe(false);
    });

    it('defaults to window.location.href when no argument is passed', () => {
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php?attachment-filter=trash', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
        expect(isTrashViewFromUrl()).toBe(true);
    });
});

describe('getMediaMode ([internal])', () => {
    afterEach(() => {
        window.Plathix = {};
        document.body.className = '';
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
    });

    it('returns "grid" when URL has ?mode=grid, regardless of runtime.mediaMode', () => {
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php?mode=grid', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
        window.Plathix = { mediaMode: 'list' };

        expect(getMediaMode()).toBe('grid');
    });

    it('returns "list" when URL has ?mode=list', () => {
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php?mode=list', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
        window.Plathix = { mediaMode: 'grid' };

        expect(getMediaMode()).toBe('list');
    });

    it('falls back to runtime.mediaMode when URL has no mode param', () => {
        window.Plathix = { mediaMode: 'list' };

        expect(getMediaMode()).toBe('list');
    });

    it('defaults to "grid" when neither URL nor runtime.mediaMode is set', () => {
        window.Plathix = {};

        expect(getMediaMode()).toBe('grid');
    });

    it('ignores document.body.classList even when it contradicts URL/runtime (regression)', () => {
        // Симулирует стороннее (WP core / page builder) изменение DOM post-load — раньше
        // это меняло getMediaMode() без re-render, теперь игнорируется ([internal]).
        window.Plathix = { mediaMode: 'list' };
        document.body.classList.add('mode-grid');

        expect(getMediaMode()).toBe('list');
    });
});

describe('getFilterStrategy ([internal])', () => {
    afterEach(() => {
        window.Plathix = {};
        document.body.className = '';
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
    });

    it('stays consistent with PHP-provided filterStrategy when no URL override and DOM disagrees', () => {
        // PHP отдал 'static-list' при рендере (upload.php?mode=list). Сторонний код меняет
        // body-класс на mode-grid post-load, без URL-навигации. До фикса это заставляло
        // getFilterStrategy() пересчитать в 'media-frame', расходясь с PHP.
        window.Plathix = { screenBase: 'upload', screenKind: 'static', mediaMode: 'list', filterStrategy: 'static-list' };
        document.body.classList.add('mode-grid');

        expect(getFilterStrategy()).toBe('static-list');
    });

    it('respects explicit URL ?mode=grid as the legitimate override', () => {
        Object.defineProperty(window, 'location', {
            value: { href: 'http://localhost/wp-admin/upload.php?mode=grid', origin: 'http://localhost' },
            writable: true,
            configurable: true,
        });
        window.Plathix = { screenBase: 'upload', screenKind: 'static', mediaMode: 'list', filterStrategy: 'static-list' };

        expect(getFilterStrategy()).toBe('media-frame');
    });
});

describe('resolveMediaFrame ([internal], [internal])', () => {
    afterEach(() => {
        delete window.wp;
    });

    it('returns wp.media.frame when set (unchanged first source)', () => {
        const frame = { id: 'frame-1', on: jest.fn() };
        window.wp = { media: { frame } };

        expect(resolveMediaFrame()).toBe(frame);
    });

    it('falls back to a named wp.media.frames.<key> slot when wp.media.frame is unset (Divi/Bricks pattern)', () => {
        const frame = { id: 'et_file_frame', on: jest.fn() };
        window.wp = { media: { frames: { et_file_frame: frame } } };

        expect(resolveMediaFrame()).toBe(frame);
    });

    it('returns undefined when neither wp.media.frame nor any wp.media.frames entry is set', () => {
        window.wp = { media: {} };

        expect(resolveMediaFrame()).toBeUndefined();
    });

    it('ignores wp.media.frames entries without an .on method (not a Backbone frame instance)', () => {
        window.wp = { media: { frames: { junk: { notAFrame: true } } } };

        expect(resolveMediaFrame()).toBeUndefined();
    });

    it('prefers wp.media.frame over wp.media.frames when both are present', () => {
        const singletonFrame = { id: 'singleton', on: jest.fn() };
        const namedFrame = { id: 'named', on: jest.fn() };
        window.wp = { media: { frame: singletonFrame, frames: { custom_key: namedFrame } } };

        expect(resolveMediaFrame()).toBe(singletonFrame);
    });
});

describe('getMediaFrame ([internal], [internal])', () => {
    afterEach(() => {
        delete window.wp;
        delete window.Plathix;
    });

    it('returns null when shouldUseMediaFrameFiltering() guard is not satisfied', () => {
        window.Plathix = { screenKind: 'static', screenBase: 'edit' };
        window.wp = { media: { frame: { id: 'frame-1', on: jest.fn() } } };

        expect(getMediaFrame()).toBeNull();
    });

    it('returns the resolved frame (via wp.media.frames fallback) when the guard is satisfied', () => {
        window.Plathix = { screenKind: 'modal' };
        const frame = { id: 'et_file_frame', on: jest.fn() };
        window.wp = { media: { frames: { et_file_frame: frame } } };

        expect(getMediaFrame()).toBe(frame);
    });

    it('returns null (not undefined) when the guard is satisfied but no frame is resolvable', () => {
        window.Plathix = { screenKind: 'modal' };
        window.wp = { media: {} };

        expect(getMediaFrame()).toBeNull();
    });
});

describe('getFeatures ([internal], [internal])', () => {
    afterEach(() => {
        window.Plathix = {};
    });

    it('defaults dnd/uploadSync to true when top-level keys are absent', () => {
        window.Plathix = {};

        expect(getFeatures()).toEqual({ dnd: true, uploadSync: true });
    });

    it('reads dnd/uploadSync from top-level runtime keys, not from a features[] object', () => {
        window.Plathix = { dnd: false, uploadSync: false, features: { dnd: true, uploadSync: true } };

        expect(getFeatures()).toEqual({ dnd: false, uploadSync: false });
    });

    it('does not return tree or replaceMedia keys (dead code removed)', () => {
        window.Plathix = { tree: false, replaceMedia: false };

        const result = getFeatures();
        expect(result).not.toHaveProperty('tree');
        expect(result).not.toHaveProperty('replaceMedia');
    });
});
