jest.mock('alpinejs', () => ({
    store: jest.fn(),
}));

jest.mock('../media-frame-watcher.js', () => ({
    onMediaFrameReady: jest.fn(),
}));

jest.mock('../runtime.js', () => ({
    getFeatures: jest.fn(() => ({ dnd: false, uploadSync: false })),
    getRuntime: jest.fn(() => ({})),
}));

jest.mock('../modal-bootstrap.js', () => ({
    bootstrapModalSidebar: jest.fn(),
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

jest.mock('../infinite-scroll.js', () => ({
    infiniteScrollManager: { init: jest.fn() },
}));

import Alpine from 'alpinejs';
import { bootstrapModal } from '../bootstrap-modal.js';
import { onMediaFrameReady } from '../media-frame-watcher.js';
import { getRuntime } from '../runtime.js';

describe('bindInitialModalFilter ([internal] — [internal])', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        getRuntime.mockReturnValue({});
    });

    it('does NOT apply persisted folder filter when isForeignContext is true', () => {
        getRuntime.mockReturnValue({ isForeignContext: true });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 7, applyFolderFilter });

        bootstrapModal();
        const readyCallback = onMediaFrameReady.mock.calls[0][0];
        readyCallback();

        expect(applyFolderFilter).not.toHaveBeenCalled();
    });

    it('applies persisted folder filter as before when isForeignContext is false', () => {
        getRuntime.mockReturnValue({ isForeignContext: false });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 7, applyFolderFilter });

        bootstrapModal();
        const readyCallback = onMediaFrameReady.mock.calls[0][0];
        readyCallback();

        expect(applyFolderFilter).toHaveBeenCalledWith(7);
    });

    it('applies persisted folder filter as before when isForeignContext is undefined (own Plathix context)', () => {
        getRuntime.mockReturnValue({});
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 7, applyFolderFilter });

        bootstrapModal();
        const readyCallback = onMediaFrameReady.mock.calls[0][0];
        readyCallback();

        expect(applyFolderFilter).toHaveBeenCalledWith(7);
    });

    it('does not call applyFolderFilter when openId is 0, regardless of isForeignContext', () => {
        getRuntime.mockReturnValue({ isForeignContext: false });
        const applyFolderFilter = jest.fn();
        Alpine.store.mockReturnValue({ openId: 0, applyFolderFilter });

        bootstrapModal();
        const readyCallback = onMediaFrameReady.mock.calls[0][0];
        readyCallback();

        expect(applyFolderFilter).not.toHaveBeenCalled();
    });
});
