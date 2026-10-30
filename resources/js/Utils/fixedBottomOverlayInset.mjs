export function fixedBottomOverlayInset({ toolbarRect, panelRect, viewportHeight, visible = true }) {
    if (!visible || !toolbarRect || !panelRect || !Number.isFinite(viewportHeight) || viewportHeight <= 0) return 0;
    if (toolbarRect.bottom < viewportHeight || toolbarRect.top >= viewportHeight) return 0;
    if (toolbarRect.right <= panelRect.left || toolbarRect.left >= panelRect.right) return 0;

    return Math.ceil(Math.max(0, Math.min(viewportHeight, viewportHeight - toolbarRect.top)));
}
