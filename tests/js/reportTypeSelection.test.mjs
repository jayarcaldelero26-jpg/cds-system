import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';
import { resolve } from 'node:path';

let server;
const load = async path => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule(`/resources/js/${path}`);
};

test.after(async () => { await server?.close(); });

test('mapped report workflow options render correctly inside fragments and optgroups', async () => {
    const { default: FloatingSelect } = await load('Components/Form/FloatingSelect.jsx');
    const documents = ['Progress Report', 'Final Report'];
    const html = renderToStaticMarkup(React.createElement(FloatingSelect, {
        label: 'Type of Document', value: 'Progress Report', onChange() {},
        children: React.createElement(React.Fragment, null,
            React.createElement('option', { value: '' }, 'Select Type of Document'),
            ...documents.map(document => React.createElement('option', { key: document, value: document }, document)),
            React.createElement('optgroup', { key: 'legacy', label: 'Legacy' },
                React.createElement('option', { value: 'Legacy Report' }, 'Legacy Report'),
            ),
        ),
    }));

    assert.match(html, /role="combobox"[^>]*aria-expanded="false"/);
    assert.match(html, /Progress Report/);
    assert.match(html, /<option value="Progress Report" selected="">Progress Report<\/option>/);
    assert.match(html, /<option value="Final Report">Final Report<\/option>/);
    assert.match(html, /<option value="Legacy Report">Legacy Report<\/option>/);
});

test('native select change dispatch preserves the existing string report-type callback value', async () => {
    const { setNativeSelectValueAndDispatch } = await load('Components/Form/nativeSelectChange.mjs');

    class FakeSelect {
        constructor() {
            this.nativeValue = '';
            this.reactTrackedValue = '';
            this.ownerDocument = { defaultView: { HTMLSelectElement: FakeSelect, Event } };
        }
        dispatchEvent(event) {
            assert.equal(event.type, 'change');
            assert.equal(event.bubbles, true);
            if (this.nativeValue !== this.reactTrackedValue) {
                this.reactTrackedValue = this.nativeValue;
                this.onReactChange?.({ target: this });
            }
            return true;
        }
    }
    Object.defineProperty(FakeSelect.prototype, 'value', {
        get() { return this.nativeValue; },
        set(value) { this.nativeValue = String(value); },
    });

    const select = new FakeSelect();
    Object.defineProperty(select, 'value', {
        get() { return this.nativeValue; },
        set(value) {
            this.reactTrackedValue = String(value);
            Object.getOwnPropertyDescriptor(FakeSelect.prototype, 'value').set.call(this, value);
        },
    });
    let callbackValue;
    select.onReactChange = event => { callbackValue = event.target.value; };

    setNativeSelectValueAndDispatch(select, 'Final Report');

    assert.equal(callbackValue, 'Final Report');
    assert.equal(select.value, 'Final Report');
});

test('empty historical report types remain an honest empty display value', async () => {
    const { formatReportValue } = await load('Utils/dateFormatters.js');
    assert.equal(formatReportValue(null), '—');
    assert.equal(formatReportValue(''), '—');
    assert.equal(formatReportValue('Final Report'), 'Final Report');
});
