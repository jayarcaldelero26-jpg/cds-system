export function createRenderRequestIdentity() {
    let sequence = 0;
    let current = 0;

    return {
        begin() {
            current = ++sequence;
            return current;
        },
        isCurrent(requestId) {
            return requestId === current;
        },
        invalidate(requestId) {
            if (requestId === current) current = ++sequence;
        },
    };
}
