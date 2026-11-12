/** Small per-record/per-action guard for requests that can overlap. */
export function createKeyedActionFlight() {
    const pending = new Set();
    return {
        begin(key) {
            if (!key || pending.has(key)) return false;
            pending.add(key);
            return true;
        },
        finish(key) {
            return pending.delete(key);
        },
        has(key) {
            return pending.has(key);
        },
        values() {
            return [...pending];
        },
    };
}
