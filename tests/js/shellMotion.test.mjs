import assert from 'node:assert/strict';
import test from 'node:test';
import {
    animatePageEntrance,
    createNavigationHighlight,
    createThemeSwitcher,
    navigationPageKey,
    readPreferredTheme,
} from '../../resources/js/Utils/shellMotion.mjs';

const view = (reduced = false) => ({
    matchMedia: query => ({ matches: query === '(prefers-reduced-motion: reduce)' && reduced }),
});

function animationFor(element) {
    const animation = {
        cancelled: false,
        cancel() {
            this.cancelled = true;
            this.oncancel?.();
        },
    };
    element.animations.push(animation);
    return animation;
}

test('theme preference respects saved light mode and survives denied browser storage', () => {
    assert.equal(readPreferredTheme({
        localStorage: { getItem: () => 'light' },
        matchMedia: () => ({ matches: true }),
    }), false);
    assert.equal(readPreferredTheme({
        localStorage: { getItem() { throw new Error('Storage denied'); } },
        matchMedia: () => ({ matches: true }),
    }), true);
    assert.equal(readPreferredTheme(undefined), false);
});

test('theme preference preserves an old guest choice while preferring the current shared key', () => {
    const legacy = { getItem: key => key === 'cds-theme' ? 'dark' : null };
    assert.equal(readPreferredTheme({ localStorage: legacy }), true);
    const current = { getItem: key => key === 'theme' ? 'light' : 'dark' };
    assert.equal(readPreferredTheme({ localStorage: current }), false);
});

function navigationFixture(reduced = false) {
    let active = null;
    let visualRect = null;
    const attributes = new Map();
    const nav = {
        scrollLeft: 0, scrollTop: 80, clientLeft: 0, clientTop: 0,
        getBoundingClientRect: () => ({ left: 10, top: 20 }),
        querySelectorAll: () => active ? [active] : [],
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: key => attributes.delete(key),
    };
    const pill = {
        hidden: true, style: {}, animations: [],
        getBoundingClientRect() {
            return visualRect || {
                left: parseFloat(this.style.left) + 10,
                top: parseFloat(this.style.top) + 20 - nav.scrollTop,
                width: parseFloat(this.style.width),
                height: parseFloat(this.style.height),
            };
        },
        animate(frames) {
            const animation = animationFor(this);
            animation.frames = frames;
            return animation;
        },
    };
    const controller = createNavigationHighlight(nav, pill, view(reduced));
    return {
        nav, pill, controller, attributes,
        setActive(href, top, height = 40) {
            active = href ? {
                getAttribute: () => href,
                getClientRects: () => [{}],
                getBoundingClientRect: () => ({ left: 22, top, width: 240, height }),
            } : null;
        },
        setVisualRect: rect => { visualRect = rect; },
    };
}

test('navigation highlight follows the active link within a scrolled container and restores its fallback', () => {
    const fixture = navigationFixture();
    fixture.setActive('/dashboard', 50);
    fixture.controller.refresh();
    assert.equal(fixture.pill.style.top, '110px');
    assert.equal(fixture.pill.style.left, '12px');
    assert.equal(fixture.pill.style.width, '240px');
    assert.equal(fixture.pill.animations.length, 0);
    assert.equal(fixture.attributes.get('data-cds-nav-motion'), 'ready');

    fixture.setActive('/calendar', 150);
    fixture.controller.refresh();
    assert.equal(fixture.pill.animations.length, 1);
    assert.match(fixture.pill.animations[0].frames[0].transform, /-100px/);
    fixture.setActive(null);
    fixture.controller.refresh();
    assert.equal(fixture.pill.hidden, true);
    assert.equal(fixture.attributes.has('data-cds-nav-motion'), false);
    fixture.controller.dispose();
    fixture.setActive('/reports', 190);
    fixture.controller.refresh();
    assert.equal(fixture.pill.hidden, true);
});

test('rapid navigation starts from the current visual highlight rather than an obsolete destination', () => {
    const fixture = navigationFixture();
    fixture.setActive('/dashboard', 50);
    fixture.controller.refresh(false);
    fixture.setActive('/calendar', 150);
    fixture.controller.refresh();
    fixture.setVisualRect({ left: 22, top: 110, width: 240, height: 40 });
    fixture.setActive('/reports', 250);
    fixture.controller.refresh();
    assert.equal(fixture.pill.animations[0].cancelled, true);
    assert.match(fixture.pill.animations[1].frames[0].transform, /-140px/);
});

test('reduced-motion navigation and viewport remeasurement update geometry without animation', () => {
    const fixture = navigationFixture(true);
    fixture.setActive('/dashboard', 50);
    fixture.controller.refresh(false);
    fixture.setActive('/calendar', 150);
    fixture.controller.refresh();
    fixture.nav.scrollTop = 120;
    fixture.controller.refresh(false);
    assert.equal(fixture.pill.style.top, '250px');
    assert.equal(fixture.pill.animations.length, 0);
});

for (const name of ['pointerdown', 'focusin', 'keydown']) {
    test('opacity-only page fade keeps running without intercepting ' + name, () => {
        const element = new EventTarget();
        element.animations = [];
        element.animate = () => animationFor(element);
        const cleanup = animatePageEntrance(element, view());
        assert.equal(element.animations.length, 1);
        const event = new Event(name, { cancelable: true });
        element.dispatchEvent(event);
        assert.equal(event.defaultPrevented, false);
        assert.equal(element.animations[0].cancelled, false);
        cleanup();
        assert.equal(element.animations[0].cancelled, true);
    });
}

test('page entrance fades from transparent to opaque without a positional transform', () => {
    const calls = [];
    const animation = { cancel() { this.cancelled = true; } };
    const element = { animate: (frames, options) => { calls.push({ frames, options }); return animation; } };
    const stop = animatePageEntrance(element, view());
    assert.deepEqual(calls[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
    assert.deepEqual(calls[0].options, { id: 'cds-page-entrance', duration: 360, easing: 'ease-out' });
    stop();
    assert.equal(animation.cancelled, true);
});

test('page entrance handles reduced motion and missing animation support', () => {
    const element = { animate() { assert.fail('Reduced motion must not animate'); } };
    animatePageEntrance(element, view(true))();
    animatePageEntrance({}, view())();
});

function themeFixture({ reduced = false, supported = true, storageDenied = false } = {}) {
    const applied = [];
    const saved = [];
    const attributes = new Map();
    const classes = new Map();
    const transitions = [];
    const document = {
        documentElement: {
            classList: { toggle: (key, value) => classes.set(key, value) },
            setAttribute: (key, value) => attributes.set(key, value),
            removeAttribute: key => attributes.delete(key),
        },
    };
    if (supported) document.startViewTransition = commit => {
        let finish;
        const transition = {
            commit, ready: Promise.resolve(), skipped: false,
            finished: new Promise(resolve => { finish = resolve; }),
            finish: () => finish(),
            skipTransition() { this.skipped = true; },
        };
        transitions.push(transition);
        return transition;
    };
    const browser = {
        ...view(reduced),
        localStorage: {
            setItem(key, value) {
                if (storageDenied) throw new Error('Storage denied');
                saved.push([key, value]);
            },
        },
    };
    const controller = createThemeSwitcher({
        initialDark: false, document, view: browser,
        applyTheme: dark => applied.push(dark),
    });
    return { controller, document, applied, saved, transitions, attributes, classes };
}

test('rapid theme toggles keep the latest intent and an earlier completion cannot clear the active transition', async () => {
    const fixture = themeFixture();
    fixture.controller.toggle();
    fixture.controller.toggle();
    assert.equal(fixture.transitions[0].skipped, true);
    fixture.transitions[0].commit();
    fixture.transitions[0].finish();
    await Promise.resolve();
    assert.equal(fixture.applied.length, 0);
    assert.equal(fixture.attributes.has('data-cds-theme-transition'), true);
    fixture.transitions[1].commit();
    assert.deepEqual(fixture.applied, [false]);
    assert.deepEqual(fixture.saved, [['theme', 'light']]);
    assert.equal(fixture.classes.get('dark'), false);
    fixture.transitions[1].finish();
    await Promise.resolve();
    assert.equal(fixture.attributes.has('data-cds-theme-transition'), false);
});

test('unsupported or reduced-motion theme changes apply immediately even when persistence is denied', () => {
    for (const options of [{ supported: false }, { reduced: true, storageDenied: true }]) {
        const fixture = themeFixture(options);
        fixture.controller.toggle();
        assert.deepEqual(fixture.applied, [true]);
        assert.equal(fixture.classes.get('dark'), true);
        assert.equal(fixture.transitions.length, 0);
    }
});

test('a failed native transition falls back to the requested theme', () => {
    const fixture = themeFixture();
    fixture.document.startViewTransition = () => { throw new Error('Transition unavailable'); };
    fixture.controller.toggle();
    assert.deepEqual(fixture.applied, [true]);
    assert.equal(fixture.attributes.has('data-cds-theme-transition'), false);
});

test('leaving the authenticated shell invalidates an outstanding theme callback', () => {
    const fixture = themeFixture();
    fixture.controller.toggle();
    fixture.controller.dispose();
    fixture.transitions[0].commit();
    assert.equal(fixture.transitions[0].skipped, true);
    assert.equal(fixture.attributes.has('data-cds-theme-transition'), false);
    assert.deepEqual(fixture.applied, []);
});

test('a transition that commits before failing cannot apply or persist the same toggle twice', () => {
    const fixture = themeFixture();
    fixture.document.startViewTransition = commit => {
        commit();
        throw new Error('Transition failed after update');
    };
    fixture.controller.toggle();
    assert.deepEqual(fixture.applied, [true]);
    assert.deepEqual(fixture.saved, [['theme', 'dark']]);
});

test('a skipped native animation still applies its update and cleans up its rejected ready state', async () => {
    const fixture = themeFixture();
    fixture.document.startViewTransition = commit => {
        commit();
        return { ready: Promise.reject(new Error('Page hidden')), finished: Promise.resolve(), skipTransition() {} };
    };
    fixture.controller.toggle();
    await Promise.resolve();
    assert.deepEqual(fixture.applied, [true]);
    assert.equal(fixture.attributes.has('data-cds-theme-transition'), false);
});

test('page navigation includes sidebar views while filters and pagination keep their existing view', () => {
    assert.equal(navigationPageKey('/reports?year=2026&page=1'), navigationPageKey('/reports?year=2027&page=2'));
    assert.notEqual(navigationPageKey('/settings/module-management'), navigationPageKey('/settings/routing-workflow'));
    assert.notEqual(navigationPageKey('/submission-tracking?view=incoming'), navigationPageKey('/submission-tracking?view=history'));
    assert.notEqual(navigationPageKey('/bms'), navigationPageKey('/bms?tracker=1'));
    assert.notEqual(navigationPageKey('/ipaf?ipaf_tab=management'), navigationPageKey('/ipaf?ipaf_tab=revenue'));
    assert.equal(navigationPageKey('/dashboard'), navigationPageKey('/dashboard#summary'));
});
