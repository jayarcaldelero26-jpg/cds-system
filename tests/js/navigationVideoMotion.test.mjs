import assert from 'node:assert/strict';
import test from 'node:test';
import { createVideoNavigationHighlight, revealPageSections } from '../../resources/js/Utils/navigationVideoMotion.mjs';

function animation() {
    let finish;
    let reject;
    return {
        finished: new Promise((resolve, fail) => { finish = resolve; reject = fail; }),
        cancelled: false,
        finish,
        cancel() { this.cancelled = true; reject(new Error('Cancelled')); },
    };
}

function sectionFixture(count = 5, reduced = false) {
    const listeners = new Set();
    const preference = {
        matches: reduced,
        addEventListener: (_, listener) => listeners.add(listener),
        removeEventListener: (_, listener) => listeners.delete(listener),
    };
    const view = {
        innerWidth: 390, innerHeight: 844,
        matchMedia: () => preference,
        getComputedStyle: () => ({ visibility: 'visible', display: 'block' }),
        CSS: { supports: () => true },
    };
    const root = {
        ownerDocument: { defaultView: view },
        closest: () => null,
        contains: element => sections.includes(element),
        querySelectorAll: () => sections,
    };
    const sections = Array.from({ length: count }, (_, index) => ({
        kind: 'section', interactive: false, excluded: false, calls: [], style: { color: 'green' },
        box: { left: 10, right: 370, top: 50 + index, bottom: 90 + index, width: 360, height: 40 },
        closest(selector) { return selector === '[data-cds-page-content]' ? root : this.excluded ? this : null; },
        contains: () => false,
        matches: () => false,
        querySelector() { return this.interactive ? {} : null; },
        getAttribute() { return this.kind; },
        getClientRects: () => [{}],
        getBoundingClientRect() { return this.box; },
        animate(frames, options) { const value = animation(); this.calls.push({ frames, options, animation: value }); return value; },
    }));
    return { root, sections, view, listeners, preference };
}

test('visible sections stagger once with a capped delay and no lasting inline style', async () => {
    const f = sectionFixture(30);
    const stop = revealPageSections(f.root, f.view);
    assert.equal(f.sections.filter(section => section.calls.length).length, 24);
    assert.equal(f.sections[0].calls[0].options.delay, 0);
    assert.equal(f.sections[1].calls[0].options.delay, 45);
    assert.equal(f.sections[23].calls[0].options.delay, 270);
    assert.equal(f.sections[0].calls[0].options.fill, 'backwards');
    assert.deepEqual(f.sections[0].calls[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
    assert.deepEqual(f.sections[0].style, { color: 'green' });
    stop();
    assert.equal(f.listeners.size, 0);
    assert.ok(f.sections.slice(0, 24).every(section => section.calls[0].animation.cancelled));
    await Promise.resolve();
});

test('nested, hidden, offscreen and modal/skeleton descendants are excluded', () => {
    const f = sectionFixture(5);
    f.sections[0].contains = element => element === f.sections[1];
    f.sections[2].excluded = true;
    f.sections[3].box.top = 900;
    f.sections[3].box.bottom = 940;
    f.sections[4].getClientRects = () => [];
    const stop = revealPageSections(f.root);
    assert.deepEqual(f.sections.map(section => section.calls.length), [1, 0, 0, 0, 0]);
    stop();
});

test('only explicit non-form cards receive a twenty-four pixel translate; forms remain opacity-only', () => {
    const f = sectionFixture(3);
    f.sections.forEach(section => { section.kind = 'card'; });
    f.sections[1].interactive = true;
    f.sections[2].matches = () => true;
    const stop = revealPageSections(f.root);
    assert.equal(f.sections[0].calls[0].frames[0].translate, '0 24px');
    assert.equal(f.sections[1].calls[0].frames[0].translate, undefined);
    assert.equal(f.sections[2].calls[0].frames[0].translate, undefined);
    assert.ok(f.sections.every(section => section.calls[0].frames.every(frame => !('transform' in frame))));
    stop();
});

test('translate unsupported browsers keep the card opacity reveal', () => {
    const f = sectionFixture(1);
    f.sections[0].kind = 'card';
    f.view.CSS.supports = () => false;
    const stop = revealPageSections(f.root);
    assert.deepEqual(f.sections[0].calls[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
    stop();
});

test('reduced motion, absent browser APIs and hidden pending boundaries never hide content', () => {
    const f = sectionFixture(2, true);
    revealPageSections(f.root)();
    revealPageSections(null)();
    revealPageSections({}) ();
    f.preference.matches = false;
    f.root.closest = () => ({});
    revealPageSections(f.root)();
    assert.equal(f.sections[0].calls.length, 0);
});

test('a newer page reveal cancels older work and old cleanup cannot cancel the new run', () => {
    const f = sectionFixture(1);
    const oldStop = revealPageSections(f.root);
    const newStop = revealPageSections(f.root);
    oldStop();
    assert.equal(f.sections[0].calls[0].animation.cancelled, true);
    assert.equal(f.sections[0].calls[1].animation.cancelled, false);
    assert.equal(f.listeners.size, 1);
    newStop();
});

test('changing to reduced motion cancels current section effects and releases its listener', () => {
    const f = sectionFixture(2);
    revealPageSections(f.root);
    for (const listener of [...f.listeners]) listener({ matches: true });
    assert.equal(f.listeners.size, 0);
    assert.ok(f.sections.every(section => section.calls[0].animation.cancelled));
});

test('normal completion releases preference listeners without a forwards fill or styling mutation', async () => {
    const f = sectionFixture(2);
    revealPageSections(f.root);
    f.sections.forEach(section => section.calls[0].animation.finish());
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(f.listeners.size, 0);
    assert.ok(f.sections.every(section => !section.calls[0].animation.cancelled));
    assert.ok(f.sections.every(section => section.calls[0].options.fill !== 'forwards'));
});

test('an unavailable per-element animation cannot block the next usable section', () => {
    const f = sectionFixture(3);
    f.sections[0].animate = () => { throw new Error('Animation not supported'); };
    f.sections[1].animate = undefined;
    const stop = revealPageSections(f.root);
    assert.equal(f.sections[2].calls.length, 1);
    stop();
});

function navigationFixture(reduced = false) {
    const attributes = new Map();
    const view = { location: { href: 'http://cds-smart-web.test/dashboard' }, matchMedia: () => ({ matches: reduced }) };
    const links = ['/dashboard', '/calendar', '/bms', '/bms?tracker=1'].map((href, index) => ({
        href, active: index === 0, disabled: false,
        getAttribute(name) { return name === 'aria-disabled' ? String(this.disabled) : this.href; },
        getClientRects: () => [{}],
        getBoundingClientRect: () => ({ left: 22, top: 50 + index * 50, width: 240, height: 40 }),
    }));
    const nav = {
        scrollLeft: 0, scrollTop: 80, clientLeft: 0, clientTop: 0,
        getBoundingClientRect: () => ({ left: 10, top: 20 }),
        querySelectorAll: selector => selector === 'a[href]' ? links : links.filter(link => link.active),
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: key => attributes.delete(key),
    };
    const pill = {
        hidden: true, style: {}, calls: [], visualRect: null,
        getBoundingClientRect() {
            return this.visualRect || { left: parseFloat(this.style.left) + 10, top: parseFloat(this.style.top) + 20 - nav.scrollTop, width: 240, height: 40 };
        },
        animate(frames, options) {
            const value = animation();
            value.finished.catch(() => {});
            this.calls.push({ frames, options, animation: value });
            return value;
        },
    };
    const key = href => { const url = new URL(href, view.location.href); return url.pathname + (url.searchParams.get('tracker') ? '?tracker=1' : ''); };
    return { nav, pill, links, attributes, controller: createVideoNavigationHighlight(nav, pill, view, key) };
}

test('an accepted pending destination glides immediately without changing the confirmed active link', () => {
    const f = navigationFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    assert.equal(f.pill.style.top, '160px');
    assert.equal(f.links[0].active, true);
    assert.equal(f.links[1].active, false);
    assert.match(f.pill.calls[0].frames[0].transform, /-50px/);
    assert.match(f.pill.calls[0].frames[0].boxShadow, /22 163 74/);
    assert.equal(f.pill.calls[0].options.duration, 320);
    f.controller.dispose();
});

test('cancelled or failed intent rolls the decorative pill back to the confirmed route', () => {
    const f = navigationFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    f.controller.refresh(true, null);
    assert.equal(f.pill.style.top, '110px');
    assert.equal(f.pill.calls[0].animation.cancelled, true);
    assert.match(f.pill.calls[1].frames[0].transform, /50px/);
    f.controller.dispose();
});

test('resize preserves an accepted intent; commit clears it without a second travel', () => {
    const f = navigationFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/bms?tracker=1');
    f.controller.refresh(false);
    assert.equal(f.pill.style.top, '260px');
    f.links[0].active = false;
    f.links[3].active = true;
    f.controller.refresh(true, null);
    assert.equal(f.pill.style.top, '260px');
    assert.equal(f.pill.calls.length, 1);
    f.controller.dispose();
});

test('a newer intent samples the visible pill position rather than the obsolete target', () => {
    const f = navigationFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    f.pill.visualRect = { left: 22, top: 75, width: 240, height: 40 };
    f.controller.refresh(true, '/bms?tracker=1');
    assert.equal(f.pill.calls[0].animation.cancelled, true);
    assert.match(f.pill.calls[1].frames[0].transform, /-125px/);
    f.controller.dispose();
});

test('external, malformed, disabled and absent destinations use the confirmed route', () => {
    const f = navigationFixture();
    f.controller.refresh(false);
    f.links[1].disabled = true;
    for (const target of ['https://outside.invalid/calendar', 'http://[', '/calendar', '/not-in-menu']) {
        f.controller.refresh(true, target);
        assert.equal(f.pill.style.top, '110px');
    }
    assert.equal(f.pill.calls.length, 0);
    f.controller.dispose();
});

test('reduced motion snaps the pill and an absent active link restores CSS fallback', () => {
    const f = navigationFixture(true);
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    assert.equal(f.pill.style.top, '160px');
    assert.equal(f.pill.calls.length, 0);
    f.links.forEach(link => { link.active = false; });
    f.controller.refresh(true, null);
    assert.equal(f.pill.hidden, true);
    assert.equal(f.attributes.has('data-cds-nav-motion'), false);
    f.controller.dispose();
    f.links[0].active = true;
    f.controller.refresh();
    assert.equal(f.pill.hidden, true);
});
