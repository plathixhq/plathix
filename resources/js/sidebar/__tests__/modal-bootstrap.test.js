jest.mock('../mount-manager.js', () => {
    const mount = jest.fn();
    return {
        MountManager: jest.fn(() => ({ mount })),
        __mount: mount,
    };
});

jest.mock('../runtime.js', () => ({
    shouldUseMediaFrameFiltering: jest.fn(),
    isStaticLibraryScreen: jest.fn(),
}));

jest.mock('../state.js', () => ({
    hasStateFlag: jest.fn(() => false),
    setStateFlag: jest.fn(),
}));

import { MountManager, __mount } from '../mount-manager.js';
import { shouldUseMediaFrameFiltering, isStaticLibraryScreen } from '../runtime.js';
import { bootstrapModalSidebar } from '../modal-bootstrap.js';

describe('bootstrapModalSidebar', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('mounts in media-frame modal contexts', () => {
        shouldUseMediaFrameFiltering.mockReturnValue(true);
        isStaticLibraryScreen.mockReturnValue(false);

        bootstrapModalSidebar();

        expect(MountManager).toHaveBeenCalledTimes(1);
        expect(__mount).toHaveBeenCalledTimes(1);
    });

    it('does not mount on the static library screen', () => {
        shouldUseMediaFrameFiltering.mockReturnValue(true);
        isStaticLibraryScreen.mockReturnValue(true);

        bootstrapModalSidebar();

        expect(MountManager).not.toHaveBeenCalled();
        expect(__mount).not.toHaveBeenCalled();
    });

    it('does not mount when media-frame filtering is disabled', () => {
        shouldUseMediaFrameFiltering.mockReturnValue(false);
        isStaticLibraryScreen.mockReturnValue(false);

        bootstrapModalSidebar();

        expect(MountManager).not.toHaveBeenCalled();
        expect(__mount).not.toHaveBeenCalled();
    });
});
