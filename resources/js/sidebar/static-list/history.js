let _popHandler = null;
let _trashUrlGuarded = false;

/**
 * [internal] ([internal], #827): mutation-channel B — восстанавливает
 * attachment-filter=trash после ЧУЖОЙ (не нашей) мутации URL через
 * history.replaceState. Конкретный чужой актор: WP core media-grid.js
 * (Backbone Router, bindSearchHandler → Backbone.history.start({pushState:true}))
 * стирает attachment-filter из адресной строки асинхронно, ПОСЛЕ ответа
 * query-attachments AJAX — недетерминированно относительно нашего кода (см.
 * runtime.js:142-149, live-подтверждено spec/_done/[internal]).
 *
 * Почему обёртка нативного history.replaceState, а не monkey-patch
 * Backbone.history.start напрямую: сигнатура/тайминг Backbone.history.start —
 * недокументированный внутренний контракт WP core, менее стабильный к апдейтам
 * ядра, чем нативный DOM API (wp-browser-timing-skeptic, [internal] #1,
 * [internal] spec). Обёртка нативного API ловит ЛЮБОЙ replaceState-вызов
 * независимо от того, кто его сделал — WP core, сторонний плагин/билдер, наш
 * же код (pushUrl/replaceUrl выше используют history.pushState/replaceState
 * напрямую, не через эту обёртку — избегаем рекурсии).
 *
 * Почему здесь, а не в runtime.js: этот файл уже владеет history-мутациями
 * sidebar-контекста (pushUrl/replaceUrl/onPopState) — правильный layering per
 * wp-architecture-skeptic ([internal] #2): runtime.js — чистый
 * leaf-предикат-модуль, добавление туда global side-effect на window.history
 * превратило бы его в shared hub, влияющий на сторонний код (Divi/Bricks,
 * уже отмеченные в runtime.js как интеграционный риск через resolveMediaFrame()).
 *
 * Идемпотентно: повторный вызов не оборачивает уже обёрнутую функцию повторно
 * (иначе — цепочка обёрток при переинициализации бандла).
 *
 * @param {() => boolean} isTrashActive обычно isTrashViewActive() из runtime.js;
 *   передаётся как параметр (не импортируется напрямую), чтобы не создавать
 *   цикл зависимостей runtime.js↔history.js и упростить unit-тестирование.
 */
export function guardTrashUrl(isTrashActive) {
    if (_trashUrlGuarded) {
        return;
    }
    _trashUrlGuarded = true;

    const nativeReplaceState = window.history.replaceState.bind(window.history);

    window.history.replaceState = function guardedReplaceState(state, title, url) {
        nativeReplaceState(state, title, url);

        if (!isTrashActive()) {
            return;
        }

        try {
            const current = new URL(window.location.href);
            if (current.searchParams.get('attachment-filter') !== 'trash') {
                current.searchParams.set('attachment-filter', 'trash');
                nativeReplaceState(state, title, current.toString());
            }
        } catch {
            // Некорректный URL — не наш случай для восстановления, оставляем как есть.
        }
    };
}

export function pushUrl(url, state = {}) {
    history.pushState({ plathixNav: true, ...state }, '', url);
}

export function replaceUrl(url, state = {}) {
    history.replaceState({ plathixNav: true, ...state }, '', url);
}

export function onPopState(callback) {
    if (_popHandler) {
        window.removeEventListener('popstate', _popHandler);
    }
    _popHandler = (e) => callback(window.location.href, e);
    window.addEventListener('popstate', _popHandler);
}

export function removePopState() {
    if (_popHandler) {
        window.removeEventListener('popstate', _popHandler);
        _popHandler = null;
    }
}
