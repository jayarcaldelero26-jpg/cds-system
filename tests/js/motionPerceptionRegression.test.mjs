import assert from 'node:assert/strict';
import test from 'node:test';

const source = process.env.CDS_MOTION_TEST_MODULE || '../../resources/js/Utils/navigationVideoMotion.mjs';
const { createVideoNavigationHighlight, revealPageSections } = await import(source);

function navFixture() {
    let active = '/dashboard';
    const attributes = new Map();
    const links = ['/dashboard', '/calendar', '/reports'].map((href, index) => ({
        getAttribute: key => key === 'href' ? href : null,
        getClientRects: () => [{}],
        getBoundingClientRect: () => ({ left: 20, top: 40 + index * 50, width: 240, height: 40 }),
    }));
    const view = { location: { href: 'http://cds-smart-web.test/dashboard' }, matchMedia: () => ({ matches: false }) };
    const nav = {
        scrollLeft: 0, scrollTop: 0, clientLeft: 0, clientTop: 0,
        getBoundingClientRect: () => ({ left: 0, top: 0 }),
        querySelectorAll: selector => selector === 'a[href]' ? links : links.filter(link => link.getAttribute('href') === active),
        setAttribute: (key, value) => attributes.set(key, value), removeAttribute: key => attributes.delete(key),
    };
    const pill = {
        hidden: true, style: {}, calls: [],
        getBoundingClientRect() { return { left: 20, top: parseFloat(this.style.top), width: 240, height: 40 }; },
        animate(frames, options) {
            const value = { frames, options, cancelled: false, playState: 'running', cancel() { this.cancelled = true; this.playState = 'idle'; } };
            this.calls.push(value);
            return value;
        },
    };
    const key = value => new URL(value, view.location.href).pathname;
    return { nav, pill, view, links, attributes, setActive: href => { active = href; }, controller: createVideoNavigationHighlight(nav, pill, view, key) };
}

test('same-target ResizeObserver callback preserves a still-visible sidebar travel', () => {
    const f = navFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    const travel = f.pill.calls[0];
    f.controller.refresh(false);
    assert.equal(travel.cancelled, false, 'Remeasure must not snap a still-travelling pill to its destination');
    assert.equal(f.pill.calls.length, 1);
    f.controller.dispose();
});

test('pending-prop update and fast successful commit do not erase the travel already started', () => {
    const f = navFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    const travel = f.pill.calls[0];
    f.controller.refresh(true, '/calendar');
    f.setActive('/calendar');
    f.controller.refresh(true, null);
    assert.equal(travel.cancelled, false, 'Repeated current-target refreshes must not cancel the only visible animation');
    assert.equal(f.pill.calls.length, 1);
    f.controller.dispose();
});

test('a genuinely changed destination still replaces travel and cancellation still returns to confirmed route', () => {
    const f = navFixture();
    f.controller.refresh(false);
    f.controller.refresh(true, '/calendar');
    const old = f.pill.calls[0];
    f.controller.refresh(true, '/reports');
    assert.equal(old.cancelled, true);
    assert.equal(f.pill.style.top, '140px');
    f.controller.refresh(true, null);
    assert.equal(f.pill.style.top, '40px');
    f.controller.dispose();
});

function sectionsFixture() {
    const mql = { matches: false, addEventListener() {}, removeEventListener() {} };
    const view = { innerWidth: 1366, innerHeight: 768, matchMedia: () => mql, CSS: { supports: () => true } };
    const root = { ownerDocument: { defaultView: view }, closest: () => null, contains: () => true, querySelectorAll: () => [container, heading, first, second] };
    const make = (tagName, kind) => ({
        tagName, calls: [], closest: selector => selector === '[data-cds-page-content]' ? root : null,
        contains: () => false, querySelector: () => null, matches: () => false,
        getAttribute: () => kind, getClientRects: () => [{}],
        getBoundingClientRect: () => ({ left: 20, right: 400, top: 60, bottom: 200, width: 380, height: 140 }),
        animate(frames, options) { const value = { frames, options, cancel() {} }; this.calls.push(value); return value; },
    });
    const container = make('SECTION', null);
    const heading = make('H1', 'heading');
    const first = make('ARTICLE', 'card');
    const second = make('ARTICLE', 'card');
    container.contains = element => [heading, first, second].includes(element);
    return { root, view, container, heading, first, second };
}

test('generic outer panels cannot swallow the explicit heading and card stagger on other pages', () => {
    const f = sectionsFixture();
    const stop = revealPageSections(f.root, f.view);
    assert.equal(f.first.calls.length, 1, 'The inner card must animate even inside a generic shared surface');
    assert.equal(f.second.calls.length, 1);
    assert.equal(f.heading.calls.length, 1);
    assert.equal(f.container.calls.length, 0, 'Avoid substituting one broad panel fade for the actual item entrance');
    assert.ok(f.second.calls[0].options.delay > f.first.calls[0].options.delay);
    stop();
});

test('the non-dashboard page heading has a visible positional arrival without moving the page root', () => {
    const f = sectionsFixture();
    const stop = revealPageSections(f.root, f.view);
    assert.equal(f.heading.calls.length, 1);
    assert.equal(f.heading.calls[0].frames[0].translate, '0 12px');
    assert.equal(f.first.calls[0].frames[0].translate, '0 16px');
    assert.equal(f.root.style, undefined);
    stop();
});
