const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export const CRUD_FORM_INITIAL_FOCUS = ':scope';

export function resolveCrudFormInitialFocusSelector(selector) {
    return selector ?? CRUD_FORM_INITIAL_FOCUS;
}

function canReceiveInitialFocus(panel, target) {
    if (!target || typeof target.focus !== 'function') return false;
    if (target.disabled || target.hidden || target.type === 'hidden' || target.hasAttribute?.('disabled')) return false;
    if (target.getAttribute?.('aria-hidden') === 'true' || target.getAttribute?.('aria-disabled') === 'true') return false;
    if (target.closest?.('[hidden], [inert], [aria-hidden="true"]')) return false;
    if (typeof target.getClientRects === 'function' && target.getClientRects().length === 0) return false;
    return !panel.contains || panel.contains(target);
}

export function focusInitialModalTarget(panel, selector = null) {
    if (!panel || typeof panel.focus !== 'function') return null;

    let target = selector === ':scope' ? panel : (selector ? panel.querySelector?.(selector) : null);
    if (!canReceiveInitialFocus(panel, target)) {
        target = Array.from(panel.querySelectorAll?.(FOCUSABLE_SELECTOR) || [])
            .find(candidate => canReceiveInitialFocus(panel, candidate));
    }
    target ||= panel;
    target.focus({ preventScroll: true });
    return target;
}
