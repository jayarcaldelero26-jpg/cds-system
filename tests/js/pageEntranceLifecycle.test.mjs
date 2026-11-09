import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { rolldown } from 'rolldown';
import * as motion from '../../resources/js/Utils/shellMotion.mjs';

const nativeRequire = createRequire(import.meta.url);
const bundle = await rolldown({
    input: resolve('resources/js/Components/PageMotion.jsx'),
    external: id => id === 'react' || id.startsWith('react/') || id.startsWith('@') || id.startsWith('.'),
    transform: { jsx: { runtime: 'automatic' } },
});
let compiled;
try {
    const { output } = await bundle.generate({ format: 'cjs', exports: 'named' });
    compiled = output.find(item => item.type === 'chunk').code;
} finally {
    await bundle.close();
}

/** Commit the real motion component's content ref before its layout effects. */
function pageFixture({ reduced = false } = {}) {
    const slots = [];
    const pending = [];
    const animations = [];
    const browser = { matchMedia: () => ({ matches: reduced }) };
    const element = {
        ownerDocument: { defaultView: browser },
        animate(frames, options) {
            const animation = {
                id: options.id, frames, options, cancelled: false,
                cancel() { this.cancelled = true; },
            };
            animations.push(animation);
            return animation;
        },
    };
    let cursor = 0;
    let navigationPending = false;
    let page = { url: '/dashboard', props: {} };
    const runtime = Object.create(React);
    runtime.useRef = initial => {
        const index = cursor++;
        slots[index] ??= { current: initial };
        return slots[index];
    };
    runtime.useContext = () => navigationPending;
    runtime.useLayoutEffect = (effect, dependencies) => {
        const index = cursor++;
        const previous = slots[index];
        if (previous && dependencies.every((value, offset) => Object.is(value, previous.dependencies[offset]))) return;
        pending.push(() => {
            previous?.cleanup?.();
            slots[index] = { dependencies, cleanup: effect() };
        });
    };

    const module = { exports: {} };
    new Function('require', 'module', 'exports', compiled)(id => {
        if (id === 'react') return runtime;
        if (id.startsWith('react/')) return nativeRequire(id);
        if (id === '@inertiajs/react') return { usePage: () => page };
        if (id.endsWith('/shellMotion.mjs')) return motion;
        if (id.endsWith('/NavigationPendingContext.jsx')) return { NavigationPendingContext: {} };
        throw new Error('Unexpected motion dependency: ' + id);
    }, module, module.exports);

    return {
        animations,
        setPending(value) { navigationPending = value; },
        render(url, children = React.createElement('div', null, 'Page content')) {
            cursor = 0;
            page = { ...page, url };
            const content = module.exports.default({ children });
            assert.ok(content.props.ref);
            content.props.ref.current = element;
            for (const commit of pending.splice(0)) commit();
            return content;
        },
        dispose() {
            for (const slot of slots) slot?.cleanup?.();
        },
    };
}

test('pending destination waits to start the single entrance fade until reveal', () => {
    const fixture = pageFixture();
    try {
        fixture.setPending(true);
        fixture.render('/reports');
        assert.equal(fixture.animations.length, 0);
        fixture.setPending(false);
        fixture.render('/reports');
        assert.equal(fixture.animations.length, 1);
    } finally {
        fixture.dispose();
    }
});

test('shared page content fades on first committed mount and cleans up on unmount', () => {
    const fixture = pageFixture();
    try {
        const content = fixture.render('/login');
        assert.equal(content.props['data-cds-page-content'], 'true');
        assert.equal(fixture.animations.length, 1);
        assert.deepEqual(fixture.animations[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
        fixture.dispose();
        assert.equal(fixture.animations[0].cancelled, true);
    } finally {
        fixture.dispose();
    }
});

test('committed route changes restart the fade while ordinary filters retain the content view', () => {
    const fixture = pageFixture();
    try {
        const first = fixture.render('/dashboard');
        const child = React.createElement('input', { defaultValue: 'Keep this input' });
        const next = fixture.render('/reports?year=2026', child);
        assert.equal(fixture.animations.length, 2);
        assert.equal(fixture.animations[0].cancelled, true);
        assert.equal(next.props.children, child);
        assert.equal(next.key, first.key, 'Cosmetic motion must not key-remount the content');
        fixture.render('/reports?year=2027&page=2', child);
        assert.equal(fixture.animations.length, 2);
        assert.equal(fixture.animations[1].cancelled, false);
        fixture.render('/calendar');
        assert.equal(fixture.animations.length, 3);
        assert.equal(fixture.animations[1].cancelled, true);
    } finally {
        fixture.dispose();
    }
});

test('BMS, IPAF and Tracking query-based navigation all trigger a fresh page fade', () => {
    const fixture = pageFixture();
    try {
        for (const url of [
            '/bms', '/bms?tracker=1',
            '/ipaf?ipaf_tab=management', '/ipaf?ipaf_tab=revenue',
            '/submission-tracking?view=incoming', '/submission-tracking?view=history',
        ]) fixture.render(url);
        assert.equal(fixture.animations.length, 6);
        assert.equal(fixture.animations.filter(animation => !animation.cancelled).length, 1);
    } finally {
        fixture.dispose();
    }
});

test('reduced-motion preference leaves all page content immediately visible', () => {
    const fixture = pageFixture({ reduced: true });
    try {
        for (const url of ['/', '/login', '/register', '/dashboard', '/calendar', '/settings']) fixture.render(url);
        assert.equal(fixture.animations.length, 0);
    } finally {
        fixture.dispose();
    }
});

test('theme switching stops only the current page fade before its theme snapshot', () => {
    const fixture = pageFixture();
    try {
        fixture.render('/calendar');
        const unrelated = { id: 'sidebar-highlight', cancel() { assert.fail('Keep independent motion'); } };
        const applied = [];
        const controller = motion.createThemeSwitcher({
            initialDark: false,
            document: { getAnimations: () => [...fixture.animations, unrelated] },
            view: { localStorage: { setItem() {} } },
            applyTheme: value => applied.push(value),
        });
        controller.toggle();
        assert.equal(fixture.animations[0].cancelled, true);
        assert.deepEqual(applied, [true]);
        fixture.render('/calendar');
        assert.equal(fixture.animations.length, 1);
        controller.dispose();
    } finally {
        fixture.dispose();
    }
});
