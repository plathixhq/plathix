/**
 * [internal] + [internal]: core-кнопка bulk-toolbar
 * (`.delete-selected-button`, wp.media.view.DeleteSelectedButton, toolbar id
 * `deleteSelectedButton`) вызывает core save-attachment/status:inherit ИЛИ status:trash
 * в зависимости от режима — оба варианта не понимают нашу Trash-таксономию: клик молча
 * не восстанавливает и не удаляет файл. Один и тот же toolbar-view используется в обоих
 * режимах (подтверждено на живом DOM, browser proof [internal]) — значит кнопка
 * должна быть скрыта независимо от режима, когда Plathix sidebar смонтирован (рабочий
 * путь — сайдбарные кнопки Restore/«В корзину»).
 *
 * [internal] (WP-native-first рефакторинг): вместо CSS-класса-маркера на всём
 * `.media-toolbar` — скрывает/показывает напрямую `$el` уже существующего
 * `deleteSelectedButton`-view через `toolbar.get()`/`.$el.hide()`. WP Senior Dev skeptic
 * (2026-08-02, проверено чтением media-views.js/media-grid.js на живом стенде клиента A)
 * отклонил `toolbar.unset()`/`.set()` из тела issue: `unset()` вызывает
 * `Backbone.View.remove()` → `dispose()` (stopListening + undelegateEvents) — view
 * становится "мёртвым" изнутри, симметричный `.set()` с закэшированным инстансом рискует
 * вернуть кнопку с отвязанными делегированными событиями (та же категория бага, что
 * лечит этот пакет). `$el.hide()/.show()` не трогает Backbone view lifecycle/model —
 * сам view-инстанс не disposed и не пересоздаётся.
 * `wp.media.frame.content.get()` гарантированно возвращает view с toolbar к этому моменту:
 * если `.media-toolbar` уже есть в DOM (сигнал ниже), `Manage.initialize()` (media-grid.js)
 * уже отработал синхронно раньше.
 */
export function syncMediaToolbarTrashClass(store) {
    const toolbar = document.querySelector('.media-toolbar');
    if (!toolbar) {
        return;
    }
    const contentView = /** @type {{ toolbar?: { get?: (id: string) => { $el?: { hide?: () => void } } } } | undefined} */ (
        window.wp?.media?.frame?.content?.get?.()
    );
    const deleteSelectedButton = contentView?.toolbar?.get?.('deleteSelectedButton');
    deleteSelectedButton?.$el?.hide?.();
}
