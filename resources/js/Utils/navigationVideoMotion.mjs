const REVEAL_SELECTOR = '[data-cds-reveal], h1, .cds-card-surface';
const EXCLUDED_SELECTOR = '[data-cds-reveal="off"], [data-cds-navigation-skeleton], [data-cds-skeleton], [role="dialog"], [role="alertdialog"], [hidden], [inert], [aria-hidden="true"]';
const INLINE_LAYER_SELECTOR = 'form, [popover], [role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]';
const MAX_SECTIONS = 24;
const MAX_SCANNED_CANDIDATES = 256;
const STAGGER_MS = 45;
const MAX_DELAY_MS = 270;
const runs = new WeakMap();

function reducedMotion(view) {
    return view?.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
}

function visibleSection(element, root, pageBoundary, view) {
    if (!element || element === root || !root.contains(element)) return false;
    if (element.closest?.(EXCLUDED_SELECTOR)) return false;
    const boundary = element.closest?.('[data-cds-page-content]');
    if (boundary && boundary !== pageBoundary) return false;
    if (!element.getClientRects?.().length) return false;
    const style = view?.getComputedStyle?.(element);
    if (style?.visibility === 'hidden' || style?.display === 'none') return false;
    const box = element.getBoundingClientRect();
    const rowViewport = element.closest?.('[data-cds-row-viewport]');
    if (rowViewport) {
        const viewportBox = rowViewport.getBoundingClientRect();
        if (box.bottom <= viewportBox.top || box.top >= viewportBox.bottom) return false;
    }
    const height = view?.innerHeight ?? Infinity;
    const width = view?.innerWidth ?? Infinity;
    return box.width > 0 && box.height > 0 && box.bottom > 0 && box.right > 0
        && box.top < height && box.left < width;
}

function revealKind(element) {
    const marker = element.getAttribute('data-cds-reveal');
    if (marker === 'row' || (marker === 'item' && element.tagName === 'TR')) return 'row';
    if (marker === 'card' || marker === 'item') return 'card';
    if (marker === 'heading' || element.tagName === 'H1') return 'heading';
    if (marker === 'group' || marker === 'fade') return marker;
    return 'surface';
}

function revealTargets(root, view) {
    const pageBoundary = root.closest?.('[data-cds-page-content]') || root;
    const candidates = [...root.querySelectorAll(REVEAL_SELECTOR)]
        .slice(0, MAX_SCANNED_CANDIDATES)
        .map(element => ({ element, kind: revealKind(element) }))
        .filter(({ element, kind }) => kind !== 'group' && visibleSection(element, root, pageBoundary, view));

    const marked = candidates.filter(({ kind }) => kind === 'card');
    const leaves = marked.filter(({ element }) => !marked.some(item => item.element !== element && item.element.contains(element)));
    const surfaces = candidates.filter(({ element, kind }) => (kind === 'surface' || kind === 'fade')
        && !leaves.some(item => element.contains(item.element) || item.element.contains(element)));
    const surfaceLeaves = surfaces.filter(({ element }) => !surfaces.some(item => item.element !== element && item.element.contains(element)));
    const cards = [...leaves, ...surfaceLeaves];
    const standaloneRows = candidates.filter(item => item.kind === 'row'
        && !cards.some(card => card.element.contains(item.element)));
    const bodyTargets = [...cards, ...standaloneRows];
    const selected = new Set(bodyTargets);

    return candidates.filter(item => selected.has(item) || (item.kind === 'heading'
        && !bodyTargets.some(target => target.element.contains(item.element))))
        .slice(0, MAX_SECTIONS);
}

function entranceKeyframes(element, kind, view) {
    const position = view?.getComputedStyle?.(element)?.position;
    const canMove = (kind === 'card' || kind === 'row') && position !== 'fixed' && position !== 'sticky'
        && !element.matches?.('form, main, nav, [data-cds-page-content]')
        && !element.querySelector(INLINE_LAYER_SELECTOR)
        && view?.CSS?.supports?.('translate', '0 6px') === true;
    if (!canMove) return [{ opacity: 0 }, { opacity: 1 }];
    if (kind === 'heading') return [{ opacity: 0, translate: '0 12px' }, { opacity: 1, translate: '0 0' }];

    const distance = kind === 'row' ? 12 : 24;
    return [
        { offset: 0, opacity: 0, translate: `0 ${distance}px`, easing: 'ease-out' },
        { offset: 0.3, opacity: 0.95, translate: `0 ${distance * 2 / 3}px`, easing: 'cubic-bezier(0.22, 0.66, 0.32, 1)' },
        { offset: 1, opacity: 1, translate: '0 0' },
    ];
}

/**
 * Add a bounded section/card stagger to the existing, single page entrance.
 * @param {HTMLElement|null|undefined} root The committed visible page boundary.
 * @param {Window|undefined} view Its owning browser window.
 * @returns {() => void} Idempotent cancellation; DOM and inline styles stay intact.
 */
export function revealPageSections(root, view = root?.ownerDocument?.defaultView) {
    runs.get(root)?.();
    if (!root?.querySelectorAll || root.closest?.(EXCLUDED_SELECTOR) || reducedMotion(view)) return () => {};

    const sections = revealTargets(root, view);
    if (!sections.length) return () => {};

    const animations = new Map();
    const preference = view?.matchMedia?.('(prefers-reduced-motion: reduce)');
    let disposed = false;
    let stopListening = () => {};
    const settleBeforeAction = event => {
        for (const [element, animation] of animations) {
            if (element.contains(event.target)) {
                animation.cancel();
                break;
            }
        }
    };
    const release = () => {
        stopListening();
        root.removeEventListener?.('click', settleBeforeAction, true);
        root.removeEventListener?.('keydown', settleBeforeAction, true);
        if (runs.get(root) === stop) runs.delete(root);
    };
    const stop = () => {
        if (disposed) return;
        disposed = true;
        release();
        for (const animation of animations.values()) animation.cancel();
    };
    runs.set(root, stop);

    sections.forEach(({ element, kind }, index) => {
        if (typeof element.animate !== 'function' || element.contains(root.ownerDocument?.activeElement ?? null)) return;
        const frames = entranceKeyframes(element, kind, view);
        try {
            for (const animation of element.getAnimations?.() || []) {
                if (animation.id?.startsWith('cds-content-entrance-')) animation.cancel();
            }
            animations.set(element, element.animate(frames, {
                id: 'cds-content-entrance-' + index,
                duration: 400,
                delay: Math.min(index * STAGGER_MS, MAX_DELAY_MS),
                easing: frames.length === 3 ? 'linear' : 'cubic-bezier(0.22, 1, 0.36, 1)',
                fill: 'backwards',
            }));
        } catch {
            // Unsupported animation must leave the normal, fully visible DOM usable.
        }
    });
    if (!animations.size) {
        stop();
        return stop;
    }
    // Dispatch has already selected the target when click capture runs.
    root.addEventListener?.('click', settleBeforeAction, true);
    root.addEventListener?.('keydown', settleBeforeAction, true);

    const onPreferenceChange = event => { if (event.matches) stop(); };
    if (preference?.addEventListener) {
        preference.addEventListener('change', onPreferenceChange);
        stopListening = () => preference.removeEventListener('change', onPreferenceChange);
    } else if (preference?.addListener) {
        preference.addListener(onPreferenceChange);
        stopListening = () => preference.removeListener(onPreferenceChange);
    }
    const completed = [...animations.values()].map(animation => animation.finished).filter(Boolean);
    if (completed.length === animations.size) {
        Promise.allSettled(completed).then(() => {
            if (disposed) return;
            disposed = true;
            release();
        });
    }
    return stop;
}

function linkBox(nav, element) {
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

function pillTransform(box, destination) {
    return `translate(${box.left - destination.left}px, ${box.top - destination.top}px) scale(${box.width / destination.width}, ${box.height / destination.height})`;
}

function pillTravelKeyframes(previous, destination, origin) {
    const glow = 'inset 0 0 0 1px rgb(255 255 255 / 0.2), 0 0 16px rgb(22 163 74 / 0.3), 0 0 9px rgb(37 99 235 / 0.2)';
    const settleEasing = 'cubic-bezier(0.22, 1, 0.36, 1)';
    const start = { offset: 0, transform: pillTransform(previous, destination), boxShadow: glow };
    const end = {
        offset: 1,
        transform: pillTransform(destination, destination),
        boxShadow: 'inset 0 0 0 1px rgb(255 255 255 / 0.2), 0 2px 7px rgb(3 25 43 / 0.18)',
    };
    const previousBottom = previous.top + previous.height;
    const destinationBottom = destination.top + destination.height;
    const movingDown = destination.top + destination.height / 2 >= previous.top + previous.height / 2;
    const leadingEdgeAdvances = movingDown ? destinationBottom > previousBottom : destination.top < previous.top;
    if (!leadingEdgeAdvances) return [{ ...start, easing: settleEasing }, end];

    // Send the leading edge to the next item first. Limit the tail on distant jumps.
    const stretchLimit = Math.max(origin.height, destination.height) * 3;
    const top = movingDown ? Math.max(previous.top, destinationBottom - stretchLimit) : destination.top;
    const bottom = movingDown ? destinationBottom : Math.min(previousBottom, destination.top + stretchLimit);
    const stretched = { ...destination, top, height: bottom - top };

    return [
        { ...start, easing: 'cubic-bezier(0.35, 0, 0.2, 1)' },
        { offset: 0.46, transform: pillTransform(stretched, destination), boxShadow: glow, easing: settleEasing },
        end,
    ];
}

function availableLink(link) {
    return link.getClientRects().length > 0 && link.getAttribute('aria-disabled') !== 'true';
}

/** A pending destination is decorative only; confirmed aria-current stays intact. */
function navigationTarget(nav, intentUrl, pageKey, view) {
    if (intentUrl) {
        try {
            const base = view?.location?.href || 'https://cds-smart.invalid/';
            const target = new URL(String(intentUrl), base);
            const origin = new URL(base).origin;
            if (/^https?:$/.test(target.protocol) && target.origin === origin) {
                const targetKey = pageKey(target.href);
                const intentLink = [...nav.querySelectorAll('a[href]')].find(link => {
                    if (!availableLink(link)) return false;
                    try {
                        const href = new URL(link.getAttribute('href'), base);
                        return href.origin === origin && pageKey(href.href) === targetKey;
                    } catch { return false; }
                });
                if (intentLink) return intentLink;
            }
        } catch {
            // Malformed or external intent uses the confirmed active link.
        }
    }
    return [...nav.querySelectorAll('a[aria-current="page"]')]
        .filter(availableLink)
        .sort((left, right) => (right.getAttribute('href') || '').length - (left.getAttribute('href') || '').length)[0] || null;
}

/**
 * Stretch one decorative pill toward the next link while the trailing edge follows.
 * @param {HTMLElement} nav The existing navigation scroll container.
 * @param {HTMLElement} pill Its existing non-interactive highlight element.
 * @param {Window|undefined} view The container's owning window.
 * @param {(url: string) => string} pageKey The application's current page identity.
 * @returns {{refresh: (withMotion?: boolean, intentUrl?: string|URL|null) => void, dispose: () => void}}
 */
export function createVideoNavigationHighlight(nav, pill, view, pageKey) {
    let activeKey = null;
    let lastTargetBox = null;
    let intent = null;
    let animation = null;
    let disposed = false;

    return {
        refresh(withMotion = true, intentUrl = undefined) {
            if (disposed) return;
            if (intentUrl !== undefined) intent = intentUrl;
            const active = navigationTarget(nav, intent, pageKey, view);
            const next = active ? linkBox(nav, active) : null;
            const key = active?.getAttribute('href') || null;
            const unchanged = next && lastTargetBox && key === activeKey
                && ['left', 'top', 'width', 'height'].every(axis => Math.abs(next[axis] - lastTargetBox[axis]) < 0.5);
            if (unchanged && !reducedMotion(view)) {
                pill.hidden = false;
                nav.setAttribute('data-cds-nav-motion', 'ready');
                return;
            }
            const previous = pill.hidden ? null : linkBox(nav, pill);
            const alreadyTravelling = animation?.playState === 'running';
            animation?.cancel();
            animation = null;
            if (!next) {
                pill.hidden = true;
                nav.removeAttribute('data-cds-nav-motion');
                activeKey = null;
                lastTargetBox = null;
                return;
            }
            const origin = lastTargetBox ?? next;
            lastTargetBox = { ...next };
            Object.assign(pill.style, {
                left: next.left + 'px', top: next.top + 'px',
                width: next.width + 'px', height: next.height + 'px',
            });
            pill.hidden = false;
            nav.setAttribute('data-cds-nav-motion', 'ready');
            if ((withMotion || alreadyTravelling) && previous && (key !== activeKey || !unchanged) && pill.animate && !reducedMotion(view)) {
                try {
                    animation = pill.animate(pillTravelKeyframes(previous, next, origin), {
                        id: 'cds-navigation-travel', duration: 360, easing: 'linear',
                    });
                } catch {
                    animation = null;
                }
            }
            activeKey = key;
        },
        dispose() {
            disposed = true;
            animation?.cancel();
            pill.hidden = true;
            nav.removeAttribute('data-cds-nav-motion');
        },
    };
}
