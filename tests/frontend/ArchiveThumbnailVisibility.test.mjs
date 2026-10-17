import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';

function createHooks() {
    const states = [];
    const refs = [];
    const effects = [];
    let cursor = 0;
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial;
        return [states[index], next => { states[index] = typeof next === 'function' ? next(states[index]) : next; }];
    };
    runtime.useRef = initial => {
        const index = cursor++;
        if (!(index in refs)) refs[index] = { current: initial };
        return refs[index];
    };
    runtime.useEffect = callback => { effects.push(callback); };
    return {
        runtime,
        render(Component, props) { cursor = 0; effects.length = 0; return Component(props); },
        ref(index, value) { refs[index] = refs[index] || { current: null }; refs[index].current = value; },
        effects: () => effects.splice(0).map(effect => effect()).filter(cleanup => typeof cleanup === 'function'),
    };
}

async function loadThumbnail(runtime) {
    const plugin = {
        name: 'archive-thumbnail-browser-fixture',
        resolveId(source) {
            if (source === 'pdfjs-dist') return '\0thumbnail-pdfjs-shim';
            if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0thumbnail-worker-shim';
            if (source === '@iconify/react') return '\0thumbnail-icon-shim';
        },
        load(id) {
            if (id === '\0thumbnail-pdfjs-shim') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => ({ promise: Promise.reject(new Error("fixture decoder unavailable")), destroy: async () => {} });';
            if (id === '\0thumbnail-worker-shim') return 'export default "/fixture-pdf-worker.mjs";';
            if (id === '\0thumbnail-icon-shim') return 'export const Icon = () => null;';
        },
    };
    const bundle = await rolldown({
        input: resolve('resources/js/Components/SubmissionTracking/ArchiveThumbnail.jsx'),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [plugin],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? runtime : createRequire(import.meta.url)(id), module, module.exports);
    return module.exports.default || module.exports;
}

test('a verified archive card starts one protected GET only after near-visibility and safely falls back on render failure', async () => {
    const hooks = createHooks();
    const Thumbnail = await loadThumbnail(hooks.runtime);
    const originalFetch = globalThis.fetch;
    const originalObserver = globalThis.IntersectionObserver;
    const originalFixture = globalThis.location;
    const requests = [];
    let observerInstance = null;
    globalThis.location = { href: 'https://cds.test/submission-tracking/archives' };
    globalThis.fetch = async (url, options) => {
        requests.push({ url, method: 'GET', credentials: options.credentials, redirect: options.redirect });
        return new Response(new Blob(['%PDF-1.7\nfixture\n%%EOF'], { type: 'application/pdf' }), {
            status: 200,
            headers: { 'Content-Type': 'application/pdf' },
        });
    };
    globalThis.IntersectionObserver = class {
        constructor(callback, options) { this.callback = callback; this.options = options; observerInstance = this; }
        observe(target) { this.target = target; }
        disconnect() { this.disconnected = true; }
        intersect() { this.callback([{ isIntersecting: true, target: this.target }]); }
    };

    try {
        const props = {
            identity: 'scope-source-record-manifest-part-version',
            scopeKey: 'actor-scope-fixture',
            url: '/submission-tracking/final-archives/files/41?preview=1',
            type: 'application/pdf',
            filename: 'signed-final.pdf',
            kind: { icon: 'lucide:file-text', label: 'PDF', tone: 'text-rose-700 bg-rose-50' },
        };
        let tree = hooks.render(Thumbnail, props);
        hooks.ref(0, { nodeName: 'SPAN' });
        const cleanups = hooks.effects();
        assert.equal(requests.length, 0, 'offscreen cards do not fetch protected bytes');
        assert.equal(observerInstance.options.rootMargin, '240px 80px');

        observerInstance.intersect();
        observerInstance.intersect();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(requests.length, 1, 'only the first near-visibility event starts a request');
        assert.equal(requests[0].method, 'GET');
        assert.equal(requests[0].credentials, 'same-origin');
        assert.equal(requests[0].redirect, 'error');
        await new Promise(resolve => setTimeout(resolve, 0));
        tree = hooks.render(Thumbnail, props);
        assert.equal(tree.props['data-archive-thumbnail'], 'fallback', 'PDF parsing failures stay local to this card');
        assert.equal(observerInstance.disconnected, true);
        cleanups.forEach(cleanup => cleanup());
    } finally {
        if (originalFetch === undefined) delete globalThis.fetch;
        else globalThis.fetch = originalFetch;
        if (originalObserver === undefined) delete globalThis.IntersectionObserver;
        else globalThis.IntersectionObserver = originalObserver;
        if (originalFixture === undefined) delete globalThis.location;
        else globalThis.location = originalFixture;
    }
});
