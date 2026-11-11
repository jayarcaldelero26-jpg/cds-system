const MAX_REMEMBERED_IDS = 120;

export function notificationToastStorageKey(userId) {
    return `cds-smart:notification-toast-seen:v1:${String(userId || 'guest')}`;
}

export function loadNotificationToastSeenIds(storage, userId, initialNotifications = []) {
    const key = notificationToastStorageKey(userId);
    let stored = [];
    try {
        const parsed = JSON.parse(storage?.getItem(key) || '[]');
        if (Array.isArray(parsed)) stored = parsed;
    } catch {
        stored = [];
    }

    const initialIds = initialNotifications.map(item => String(item?.id || '')).filter(Boolean);
    const seen = new Set([...stored, ...initialIds].map(String).filter(Boolean));
    persistNotificationToastSeenIds(storage, key, seen);
    return seen;
}

export function takeNewReportNotifications(payload, seenIds, initializedAt) {
    const watermark = Date.parse(initializedAt || '');
    const notifications = Array.isArray(payload?.notifications) ? payload.notifications : [];
    const fresh = [];

    for (const notification of notifications) {
        const id = String(notification?.id || '');
        if (!id || seenIds.has(id) || notification?.category !== 'submission_updates') continue;
        seenIds.add(id);

        const createdAt = Date.parse(notification?.created_at || '');
        if (!Number.isNaN(watermark) && !Number.isNaN(createdAt) && createdAt >= watermark) {
            fresh.push(notification);
        }
    }

    return fresh;
}

export function persistNotificationToastSeenIds(storage, key, seenIds) {
    const recent = [...seenIds].slice(-MAX_REMEMBERED_IDS);
    seenIds.clear();
    recent.forEach(id => seenIds.add(String(id)));
    try {
        storage?.setItem(key, JSON.stringify(recent));
    } catch {
        // In-memory deduplication still applies when browser storage is disabled.
    }
}
