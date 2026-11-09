import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { isAbsolute, resolve } from 'node:path';
import React from 'react';
import { rolldown } from 'rolldown';
import * as motion from '../../resources/js/Utils/shellMotion.mjs';

const nativeRequire = createRequire(import.meta.url);
const appPath = resolve('resources/js/app.jsx');

async function compile(input, plugins = []) {
    const bundle = await rolldown({
        input,
        external: id => !isAbsolute(id),
        plugins,
        transform: { jsx: { runtime: 'automatic' } },
    });
    try {
        const { output } = await bundle.generate({ format: 'cjs', exports: 'named' });
        return output.find(item => item.type === 'chunk').code;
    } finally {
        await bundle.close();
    }
}

const pageModule = { exports: {} };
const compiledMotion = await compile(resolve('resources/js/Components/PageMotion.jsx'));
new Function('require', 'module', 'exports', compiledMotion)(id => {
    if (id === 'react' || id.startsWith('react/')) return nativeRequire(id);
    if (id === '@inertiajs/react') return { usePage() { throw new Error('Resolver coverage must not render page hooks'); } };
    if (id.endsWith('/shellMotion.mjs')) return motion;
    if (id.endsWith('/NavigationPendingContext.jsx')) return { NavigationPendingContext: {} };
    throw new Error('Unexpected page-motion dependency: ' + id);
}, pageModule, pageModule.exports);

const compiledApp = await compile(appPath, [{
    name: 'controlled-page-resolver-fixtures',
    load(id) {
        if (resolve(id) !== appPath) return null;
        const source = readFileSync(id, 'utf8');
        const glob = "import.meta.glob('./Pages/**/*.jsx')";
        assert.ok(source.includes(glob), 'Keep coverage attached to the actual application resolver');
        return "import { pageModules as testPages } from 'cds-motion-fixtures';\n"
            + source.replace(glob, 'testPages').replace('import.meta.env.VITE_APP_NAME', "'CDS-SMART'");
    },
}]);

function pageNames(directory = resolve('resources/js/Pages'), prefix = '') {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const name = prefix + entry.name;
        if (entry.isDirectory()) return pageNames(resolve(directory, entry.name), name + '/');
        return name.endsWith('.jsx') ? [name.slice(0, -4)] : [];
    });
}

function resolverFixture() {
    const entries = {};
    const originals = new Map();
    const renders = [];
    function AuthenticatedShell({ children }) { return children; }
    let config;
    const addPage = (name, layout) => {
        const Page = function SourcePage() { return null; };
        Page.displayName = name;
        if (layout !== undefined) Page.layout = layout;
        originals.set(name, Page);
        entries['./Pages/' + name + '.jsx'] = async () => ({ default: Page });
        return Page;
    };
    for (const name of pageNames()) addPage(name);

    const module = { exports: {} };
    new Function('require', 'module', 'exports', compiledApp)(id => {
        if (id === 'react' || id.startsWith('react/')) return nativeRequire(id);
        if (id === '@inertiajs/react') return { createInertiaApp: value => { config = value; } };
        if (id === 'laravel-vite-plugin/inertia-helpers') return {
            resolvePageComponent: async (path, modules) => {
                assert.ok(modules[path], 'The requested page must exist in the controlled source catalog');
                return modules[path]();
            },
        };
        if (id === 'react-dom/client') return { createRoot: () => ({ render: element => renders.push(element) }) };
        if (id.endsWith('/AuthenticatedLayout')) return { AuthenticatedShell };
        if (id.endsWith('/PageMotion')) return pageModule.exports;
        if (id.endsWith('/shellMotion.mjs')) return motion;
        if (id.endsWith('.css')) return {};
        if (id === 'cds-motion-fixtures') return { pageModules: entries };
        throw new Error('Unexpected application dependency: ' + id);
    }, module, module.exports);
    assert.equal(typeof config?.resolve, 'function');
    return { config, originals, AuthenticatedShell, addPage, renders };
}

test('the actual resolver gives every discoverable page entry one shared motion boundary', async () => {
    const fixture = resolverFixture();
    const names = pageNames();
    assert.ok(names.includes('Welcome'));
    assert.ok(names.includes('Auth/Login'));
    assert.ok(names.includes('PamoReports/Index'));
    assert.ok(names.includes('Admin/OfficeTat/Index'));
    for (const name of names) {
        const Wrapped = await fixture.config.resolve(name);
        const source = fixture.originals.get(name);
        const props = { marker: name };
        const boundary = Wrapped(props);
        assert.equal(boundary.type, pageModule.exports.default, name);
        assert.equal(boundary.props.children.type, source, name);
        assert.equal(boundary.props.children.props.marker, name);
        if (name === 'Welcome' || name.startsWith('Auth/')) {
            assert.equal(Wrapped.layout, undefined, 'Public pages keep their own layout: ' + name);
        } else {
            assert.equal(Wrapped.layout(boundary).type, fixture.AuthenticatedShell, name);
        }
    }
});

test('repeated resolution retains wrapper identity and does not add a URL key to the form', async () => {
    const fixture = resolverFixture();
    const first = await fixture.config.resolve('Calendar/Index');
    const again = await fixture.config.resolve('Calendar/Index');
    const other = await fixture.config.resolve('Dashboard');
    assert.equal(again, first);
    assert.notEqual(other, first);
    const boundary = first({ form: { notes: 'Unsaved fixture value' } });
    assert.equal(boundary.key, null);
    assert.equal(boundary.props.children.key, null);
    assert.deepEqual(boundary.props.children.props.form, { notes: 'Unsaved fixture value' });
});

test('existing function and array layouts are preserved instead of replaced by an authenticated shell', async () => {
    const fixture = resolverFixture();
    const functionLayout = page => React.createElement('section', { 'data-fixture-layout': true }, page);
    const arrayLayout = [function Outer() {}, function Inner() {}];
    for (const [name, layout] of [['Custom/Function', functionLayout], ['Custom/Array', arrayLayout]]) {
        fixture.addPage(name, layout);
        const Wrapped = await fixture.config.resolve(name);
        assert.equal(Wrapped.layout, layout);
        assert.equal(fixture.originals.get(name).layout, layout);
    }
});

test('a future page uses motion without a page-name or role allowlist', async () => {
    const fixture = resolverFixture();
    const source = fixture.addPage('Future/Module');
    const Wrapped = await fixture.config.resolve('Future/Module');
    for (const role of ['PAMO', 'CENRO Records', 'CDS Focal', 'CDS Chief', 'Super Admin']) {
        const auth = { user: { roles: [role], permissions: ['fixture.read'] } };
        const boundary = Wrapped({ auth });
        assert.equal(boundary.type, pageModule.exports.default);
        assert.equal(boundary.props.children.type, source);
        assert.equal(boundary.props.children.props.auth, auth);
        assert.deepEqual(auth.user.permissions, ['fixture.read']);
    }
});

test('application boot restores the same saved theme before rendering public or internal content', () => {
    const fixture = resolverFixture();
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const classes = [];
    globalThis.document = { documentElement: { classList: { toggle: (...args) => classes.push(args) } } };
    globalThis.window = { localStorage: { getItem: key => key === 'theme' ? 'dark' : null } };
    try {
        const props = { initialPage: { component: 'Welcome' } };
        const App = () => null;
        fixture.config.setup({ el: {}, App, props });
        assert.deepEqual(classes, [['dark', true]]);
        assert.equal(fixture.renders[0].type, App);
        assert.equal(fixture.renders[0].props.initialPage, props.initialPage);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});
