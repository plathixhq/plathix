import { notificationsModule } from '../notifications.js';
import { mergeStore } from '../utils.js';

function makeStore() {
    return Object.assign(Object.create(null), mergeStore(notificationsModule), {
        notifications: [],
        _notifId: 0,
        _notifTimers: {},
    });
}

describe('notificationsModule', () => {
    beforeEach(() => {
        jest.useFakeTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    it('notify() pushes a notification with incrementing id', () => {
        const store = makeStore();
        store.notify('success', 'Hello');
        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0]).toMatchObject({ type: 'success', message: 'Hello' });
        store.notify('error', 'Oops');
        expect(store.notifications[1].id).toBeGreaterThan(store.notifications[0].id);
    });

    it('notify() auto-dismisses after 6 s', () => {
        const store = makeStore();
        store.notify('info', 'Auto');
        expect(store.notifications).toHaveLength(1);
        jest.advanceTimersByTime(6000);
        expect(store.notifications).toHaveLength(0);
    });

    it('dismissNotification() removes by id', () => {
        const store = makeStore();
        store.notify('success', 'A');
        store.notify('success', 'B');
        const id = store.notifications[0].id;
        store.dismissNotification(id);
        expect(store.notifications).toHaveLength(1);
        expect(store.notifications[0].message).toBe('B');
    });

    it('dismissNotification() with unknown id is a no-op', () => {
        const store = makeStore();
        store.notify('info', 'X');
        expect(() => store.dismissNotification(9999)).not.toThrow();
        expect(store.notifications).toHaveLength(1);
    });

    it('notify() updates existing keyed notification instead of duplicating it', () => {
        const store = makeStore();
        const id = store.notify('info', 'Uploading...', { key: 'upload-session', duration: 0 });
        const sameId = store.notify('info', 'Still uploading...', { key: 'upload-session', duration: 0 });
        expect(store.notifications).toHaveLength(1);
        expect(sameId).toBe(id);
        expect(store.notifications[0].message).toBe('Still uploading...');
    });

    it('dismissNotificationByKey() removes keyed notification', () => {
        const store = makeStore();
        store.notify('info', 'Uploading...', { key: 'upload-session', duration: 0 });
        store.dismissNotificationByKey('upload-session');
        expect(store.notifications).toHaveLength(0);
    });
});
