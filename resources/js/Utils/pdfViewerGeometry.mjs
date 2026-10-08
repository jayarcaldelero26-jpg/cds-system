export function fitPageScale(pageWidth, pageHeight, availableWidth, availableHeight) {
    if (![pageWidth, pageHeight, availableWidth, availableHeight].every(Number.isFinite)
        || pageWidth <= 0 || pageHeight <= 0 || availableWidth <= 0 || availableHeight <= 0) return 1;
    return Math.min(availableWidth / pageWidth, availableHeight / pageHeight);
}

export function nextPage(current, total, direction) {
    if (!Number.isInteger(total) || total < 1) return 1;
    return Math.min(total, Math.max(1, current + direction));
}

export function nearestPageToViewportCenter(pages, viewportTop, viewportHeight) {
    if (!Array.isArray(pages) || !Number.isFinite(viewportTop) || !Number.isFinite(viewportHeight) || viewportHeight < 0) return null;
    const center = viewportTop + viewportHeight / 2;
    let nearest = null;
    let distance = Number.POSITIVE_INFINITY;
    for (const page of pages) {
        if (!Number.isInteger(page?.number) || !Number.isFinite(page.top) || !Number.isFinite(page.bottom)) continue;
        const candidate = Math.abs((page.top + page.bottom) / 2 - center);
        if (candidate < distance) {
            nearest = page.number;
            distance = candidate;
        }
    }
    return nearest;
}

export function nextZoom(current, direction) {
    const factor = direction > 0 ? 1.2 : 1 / 1.2;
    return Math.min(4, Math.max(0.25, current * factor));
}

export function zoomedScrollOffset(scrollOffset, pointerOffset, fromScale, toScale) {
    if (![scrollOffset, pointerOffset, fromScale, toScale].every(Number.isFinite) || fromScale <= 0 || toScale <= 0) return scrollOffset;
    return Math.max(0, (scrollOffset + pointerOffset) * (toScale / fromScale) - pointerOffset);
}
