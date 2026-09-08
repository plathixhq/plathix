jest.mock('alpinejs', () => ({
    store: jest.fn(),
}));

jest.mock('../static-bootstrap.js', () => ({
    bootstrapStaticSidebar: jest.fn(),
}));

jest.mock('../modal-bootstrap.js', () => ({
    installModalMediaPatches: jest.fn(),
}));

jest.mock('../dnd.js', () => ({
    enableAttachmentDnD: jest.fn(),
}));

jest.mock('../attachment-events.js', () => ({
    bindAttachmentDeleteEvents: jest.fn(),
    bindSelectedMediaCountEvents: jest.fn(),
}));

jest.mock('../upload-events.js', () => ({
    bindUploadCompleteEvents: jest.fn(),
}));

jest.mock('../upload-link-context.js', () => ({
    bindUploadLinkFolderContext: jest.fn(),
}));

jest.mock('../infinite-scroll.js', () => ({
    infiniteScrollManager: { init: jest.fn(), attachFrame: jest.fn() },
}));


// isTrashViewFromUrl/isTrashViewActive stay REAL — they are the exact logic under test.
jest.mock('../runtime.js', () => ({
    ...jest.requireActual('../runtime.js'),
    getFeatures: jest.fn(() => ({ dnd: false, uploadSync: false })),
    getRuntime: jest.fn(() => ({})),
    isUploadScreen: jest.fn(() => false),
    isStaticScreen: jest.fn(() => false),
}));

import Alpine from 'alpinejs';
import { bootstrapStaticGrid } from '../bootstrap-static-grid.js';
import { getRuntime } from '../runtime.js';

function setUrl(href) {
    Object.defineProperty(window, 'location', {
        value: { href, origin: 'http://localhost' },
        writable: true,
        configurable: true,
    });
}

function mockReadyMediaFrame() {
    window.wp = {
        media: {
            frame: {
                content: { get: () => ({ collection: { props: {} } }) },
                state: () => ({ get: () => undefined }),
            },
        },
    };
}

describe('handles trash workflow consistently', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        getRuntime.mockReturnValue({});
        mockReadyMediaFrame();
    });

    afterEach(() => {
        delete window.wp;
    });

    it('prioritizes URL Trash context over a stale persisted openId', () => {
        setUrl('http://localhost/wp-admin/upload.php?attachment-filter=trash');
        getRuntime.mockReturnValue({ trashFolderId: 1037 });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 42, applyFolderFilter });

        bootstrapStaticGrid();

        expect(applyFolderFilter).toHaveBeenCalledWith(1037, { resetPage: true });
    });

    it('keeps persisted openId when URL does not indicate Trash (contract unchanged)', () => {
        setUrl('http://localhost/wp-admin/upload.php?mode=grid');
        getRuntime.mockReturnValue({ trashFolderId: 1037 });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 42, applyFolderFilter });

        bootstrapStaticGrid();

        expect(applyFolderFilter).toHaveBeenCalledWith(42, { resetPage: true });
    });

    it('keeps persisted Trash openId when URL does not indicate Trash (persisted Trash preference is not erased)', () => {
        setUrl('http://localhost/wp-admin/upload.php?mode=grid');
        getRuntime.mockReturnValue({ trashFolderId: 1037 });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 1037, applyFolderFilter });

        bootstrapStaticGrid();

        expect(applyFolderFilter).toHaveBeenCalledWith(1037, { resetPage: true });
    });

    it('FOM-101 regression guard: openId<=0 with non-Trash URL still applies filter(0), not early return', () => {
        setUrl('http://localhost/wp-admin/upload.php?mode=grid');
        getRuntime.mockReturnValue({ trashFolderId: 1037 });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 0, applyFolderFilter });

        bootstrapStaticGrid();

        expect(applyFolderFilter).toHaveBeenCalledWith(0, { resetPage: true });
    });

    it('does not use trashFolderId priority when trashFolderId is unset (0)', () => {
        setUrl('http://localhost/wp-admin/upload.php?attachment-filter=trash');
        getRuntime.mockReturnValue({ trashFolderId: 0 });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 42, applyFolderFilter });

        bootstrapStaticGrid();

        expect(applyFolderFilter).toHaveBeenCalledWith(42, { resetPage: true });
    });
});
