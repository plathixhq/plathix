import { MountManager } from './mount-manager.js';
import { isStaticLibraryScreen, shouldUseMediaFrameFiltering } from './runtime.js';
import { hasStateFlag, setStateFlag } from './state.js';

function shouldBootstrapModalSidebar() {
    return shouldUseMediaFrameFiltering() && !isStaticLibraryScreen();
}

export function bootstrapModalSidebar() {
    if (!shouldBootstrapModalSidebar()) {
        return;
    }

    new MountManager().mount();
}

export function installModalMediaPatches() {
    if (!shouldBootstrapModalSidebar()) {
        return;
    }

    if (hasStateFlag('modalMediaPatched')) {
        return;
    }

    const patchValidator = (proto) => {
        if (!proto?.validator || proto._plathixValidatorPatched) return;
        const originalValidator = proto.validator;
        proto.validator = function (attachment) {
            if (attachment?.get?.('uploading')) return true;
            return originalValidator.call(this, attachment);
        };
        proto._plathixValidatorPatched = true;
    };

    patchValidator(window.wp?.media?.model?.Attachments?.prototype);
    patchValidator(window.wp?.media?.model?.Query?.prototype);

    const Attachments = window.wp?.media?.model?.Attachments;
    if (Attachments?.prototype?._requery && !Attachments.prototype._plathixRequeryPatched) {
        const originalRequery = Attachments.prototype._requery;
        Attachments.prototype._requery = function () {
            const self = this;
            const result = originalRequery.apply(this, arguments);
            const tryObserve = () => {
                const queue = window.wp?.Uploader?.queue;
                if (queue && !self.observers?.includes(queue)) {
                    self.observe(queue);
                }
            };
            tryObserve();
            setTimeout(tryObserve, 1500);
            return result;
        };
        Attachments.prototype._plathixRequeryPatched = true;
    }

    if (window.wp?.media?.model?.Attachments) {
        setStateFlag('modalMediaPatched');
    }
}
