export const notificationsModule = {
    notifications: [],
    _notifId: 0,
    _notifTimers: {},

    notify(type, message, { key = null, duration = 6000 } = {}) {
        const existing = key ? this.notifications.find((n) => n.key === key) : null;
        if (existing) {
            existing.type = type;
            existing.message = message;
            this._armDismiss(existing.id, duration);
            return existing.id;
        }

        const id = ++this._notifId;
        this.notifications.push({ id, key, type, message });
        this._armDismiss(id, duration);
        return id;
    },

    dismissNotification(id) {
        this._clearDismissTimer(id);
        const idx = this.notifications.findIndex((n) => n.id === id);
        if (idx !== -1) this.notifications.splice(idx, 1);
    },

    dismissNotificationByKey(key) {
        const notif = this.notifications.find((n) => n.key === key);
        if (notif) {
            this.dismissNotification(notif.id);
        }
    },

    _armDismiss(id, duration) {
        this._clearDismissTimer(id);
        if (!(duration > 0)) {
            return;
        }
        this._notifTimers[id] = setTimeout(() => this.dismissNotification(id), duration);
    },

    _clearDismissTimer(id) {
        if (this._notifTimers[id]) {
            clearTimeout(this._notifTimers[id]);
            delete this._notifTimers[id];
        }
    },
};
