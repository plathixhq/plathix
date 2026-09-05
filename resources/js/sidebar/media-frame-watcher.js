/**
 * [internal] ([internal]): единственный владелец обнаружения "media frame
 * появился в DOM". WP core не даёт глобального события "любая модалка wp.media
 * открылась" — ни wp.media.on (не существует), ни wp.media.events.on('open', ...)
 * (core триггерит 'open' на конкретном frame/controller instance, не на общем
 * media.events bus). Единственный надёжный сигнал — появление узла .media-frame в DOM,
 * тот же факт, который раньше независимо наблюдал только mount-manager.js.
 *
 * [internal] ([internal]): резолв самого frame instance (не факт "он
 * появился") делегирован resolveMediaFrame() в runtime.js — Divi/Bricks кладут instance
 * в именованный wp.media.frames.<key>, не в wp.media.frame singleton, который здесь
 * читался напрямую раньше.
 */

import { resolveMediaFrame } from './runtime.js';
import { setStateValue } from './state.js';

const callbacks = new Set();
let lastKnownFrame = null;
let bodyObserver = null;

function notify(frame) {
    if (!frame || frame === lastKnownFrame) {
        return;
    }
    lastKnownFrame = frame;
    callbacks.forEach((cb) => cb(frame));
}

function watchBody() {
    if (bodyObserver || typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
        return;
    }

    bodyObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof HTMLElement)) continue;
                const frameEl = node.classList.contains('media-frame')
                    ? node
                    : node.querySelector?.('.media-frame');
                if (frameEl) {
                    const frame = resolveMediaFrame();
                    if (frame) {
                        notify(frame);
                    }
                }
            }
        }
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });
    // [internal] ([internal]): наблюдатель живёт весь page load, production
    // не вызывает disconnect() (нет lifecycle-точки в классическом WP-admin —
    // страница живёт до full page reload). Сохранён для тестового cleanup, тот
    // же паттерн, что attachmentDnDObserver в dnd.js.
    setStateValue('mediaFrameBodyObserver', bodyObserver);
}

/**
 * Подписывает callback(frame) на каждое появление media frame в DOM. Если frame уже
 * существует на момент вызова, callback выполняется сразу синхронно. Вызывается заново
 * при каждом новом открытии модалки (новый frame instance), не только при первом.
 */
export function onMediaFrameReady(callback) {
    callbacks.add(callback);
    watchBody();

    const frame = resolveMediaFrame();
    if (frame) {
        callback(frame);
    }
}
