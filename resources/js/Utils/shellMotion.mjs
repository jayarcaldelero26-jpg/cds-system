import { createVideoNavigationHighlight, revealPageSections } from './navigationVideoMotion.mjs';

export function navigationPageKey(value = '/') {
    const url = new URL(value, 'https://cds-smart.invalid');
    const navigation = new URLSearchParams();
    for (const key of ['view', 'tracker', 'ipaf_tab']) {
        const value = url.searchParams.get(key);
        if (value) navigation.set(key, value);
    }
    const query = navigation.toString();

    return url.pathname + (query ? '?' + query : '');
}

export function prefersReducedMotion(view = globalThis.window) {
    return view?.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
}

export function readPreferredTheme(view = globalThis.window) {
    try {
        for (const key of ['theme', 'cds-theme']) {
            const saved = view?.localStorage?.getItem(key);
            if (saved === 'dark' || saved === 'light') return saved === 'dark';
        }
    } catch {
        // The device preference also works when browser storage is unavailable.
    }
    return view?.matchMedia?.('(prefers-color-scheme: dark)')?.matches === true;
}

/** Fade committed content without moving controls or intercepting their input. */
export function animatePageEntrance(element, view = element?.ownerDocument?.defaultView) {
    if (!element?.animate || prefersReducedMotion(view)) return () => {};
    const animation = element.animate([
        { opacity: 0 },
        { opacity: 1 },
    ], { id: 'cds-page-entrance', duration: 360, easing: 'ease-out' });
    const revealRoot = element.querySelector?.('[data-cds-page-content]') || element;
    const stopSections = revealPageSections(revealRoot, view);

    return () => {
        stopSections();
        animation.cancel();
    };
}

/** Stop only content entrances before the document takes a theme snapshot. */
export function stopPageEntrances(document = globalThis.document) {
    for (const animation of document?.getAnimations?.() || []) {
        if (animation.id === 'cds-page-entrance' || animation.id?.startsWith('cds-content-entrance-')) animation.cancel();
    }
}

function navigationBox(nav, element) {
    const outer = nav.getBoundingClientRect();
    const inner = element.getBoundingClientRect();
    if (inner.width <= 0 || inner.height <= 0) return null;

    return {
        left: inner.left - outer.left + nav.scrollLeft - nav.clientLeft,
        top: inner.top - outer.top + nav.scrollTop - nav.clientTop,
        width: inner.width,
        height: inner.height,
    };
}

/** One decorative layer follows the confirmed active navigation link. */
export function createNavigationHighlight(nav, pill, view = nav?.ownerDocument?.defaultView) {
    return createVideoNavigationHighlight(nav, pill, view, navigationPageKey);
}

/** Latest theme intent wins even when an earlier transition is still queued. */
export function createThemeSwitcher({ initialDark, applyTheme, document = globalThis.document, view = globalThis.window }) {
    let targetDark = initialDark;
    let generation = 0;
    let transition = null;
    const root = document?.documentElement;
    const clearTransition = () => {
        transition = null;
        root?.removeAttribute('data-cds-theme-transition');
    };

    return {
        toggle() {
            stopPageEntrances(document);
            targetDark = !targetDark;
            const next = targetDark;
            const currentGeneration = ++generation;
            transition?.skipTransition();
            clearTransition();
            let committed = false;
            const commit = () => {
                if (currentGeneration !== generation || committed) return;
                committed = true;
                root?.classList.toggle('dark', next);
                applyTheme(next);
                try {
                    view?.localStorage?.setItem('theme', next ? 'dark' : 'light');
                } catch {
                    // A denied storage write does not prevent an in-session theme change.
                }
            };

            if (!document?.startViewTransition || prefersReducedMotion(view)) {
                commit();
                return;
            }

            root.setAttribute('data-cds-theme-transition', 'active');
            try {
                const currentTransition = document.startViewTransition(commit);
                transition = currentTransition;
                currentTransition.ready.catch(() => {});
                const finish = () => {
                    if (currentGeneration === generation) clearTransition();
                };
                currentTransition.finished.then(finish, finish);
            } catch {
                commit();
                clearTransition();
            }
        },
        dispose() {
            generation++;
            transition?.skipTransition();
            clearTransition();
        },
    };
}
