const layers = new Map();
let sequence = 0;
let listeningDocument = null;
let originalBodyOverflow = null;

function stopEvent(event) {
    event.preventDefault();
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    else event.stopPropagation();
}

function visibleFocusable(panel) {
    const selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    return Array.from(panel?.querySelectorAll?.(selector) || []).filter(element =>
        element.getClientRects?.().length !== 0 && element.getAttribute?.('aria-hidden') !== 'true'
    );
}

export function topModalLayer(entries = [...layers.values()]) {
    const visible = [...entries].filter(entry => !entry.layer?.getClientRects || entry.layer.getClientRects().length > 0);
    return visible.sort((left, right) => (left.zIndex - right.zIndex) || (left.order - right.order)).at(-1) || null;
}

export function dispatchModalKeydown(event, entries = [...layers.values()]) {
    if (event.key !== 'Escape' && event.key !== 'Tab') return false;
    const top = topModalLayer(entries);
    if (!top || top.suspendKeyboardHandling) return false;

    const target = event.target;
    const openControl = target?.closest?.('[aria-expanded="true"]');
    const modalDisclosure = openControl?.hasAttribute?.('data-cds-modal-disclosure');
    const nestedPopup = target?.closest?.('.cds-select-menu, .cds-filter-dropdown, .cds-menu-panel, .cds-filter-panel, [role="listbox"], [role="dialog"]:not([aria-modal="true"])');
    if ((openControl && !modalDisclosure && (top.panel?.contains?.(openControl) || nestedPopup)) || nestedPopup) return false;

    if (event.key === 'Escape') {
        stopEvent(event);
        if (top.closeOnEscape) top.onEscape?.();
        return true;
    }

    const panel = top.panel;
    if (!panel) return false;
    const focusable = visibleFocusable(panel);
    const active = panel.ownerDocument?.activeElement;
    const outside = !panel.contains?.(active);
    if (!focusable.length) {
        stopEvent(event);
        panel.focus?.();
        return true;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if ((event.shiftKey && (active === first || outside)) || (!event.shiftKey && (active === last || outside))) {
        stopEvent(event);
        (event.shiftKey ? last : first).focus?.();
        return true;
    }

    // Only the top layer owns Tab. Its normal in-dialog tab sequence remains native.
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    else event.stopPropagation();
    return true;
}

function handleDocumentKeydown(event) {
    dispatchModalKeydown(event);
}

function lockBody(documentObject) {
    if (originalBodyOverflow !== null || !documentObject?.body) return;
    originalBodyOverflow = documentObject.body.style.overflow;
    documentObject.body.style.overflow = 'hidden';
}

function unlockBody(documentObject) {
    if (originalBodyOverflow === null || !documentObject?.body) return;
    documentObject.body.style.overflow = originalBodyOverflow;
    originalBodyOverflow = null;
}

export function registerModalLayer(layer) {
    const documentObject = layer.layer?.ownerDocument || layer.panel?.ownerDocument || globalThis.document;
    const entry = { ...layer, order: ++sequence, documentObject };
    layers.set(entry.id, entry);
    if (documentObject && listeningDocument !== documentObject) {
        listeningDocument?.removeEventListener('keydown', handleDocumentKeydown, true);
        listeningDocument = documentObject;
        listeningDocument.addEventListener('keydown', handleDocumentKeydown, true);
    }
    lockBody(documentObject);

    return () => {
        layers.delete(entry.id);
        if (!layers.size) {
            listeningDocument?.removeEventListener('keydown', handleDocumentKeydown, true);
            unlockBody(documentObject);
            listeningDocument = null;
        }
    };
}

export function activeModalLayerCount() {
    return layers.size;
}
