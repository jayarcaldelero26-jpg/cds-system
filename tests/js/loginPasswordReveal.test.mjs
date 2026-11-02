import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;
const load = async path => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true, hmr: false },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule(`/resources/js/${path}`);
};

test.after(async () => { await server?.close(); });

test('Login password starts masked and its inline eye toggles without becoming a submit or action tab', async () => {
    const { AuthField, PasswordVisibilityToggle } = await load('Components/AuthField.jsx');
    const loginSource = await readFile(new URL('../../resources/js/Pages/Auth/Login.jsx', import.meta.url), 'utf8');
    const changes = [];
    const hiddenToggle = PasswordVisibilityToggle({ visible: false, onToggle: () => changes.push('toggle') });
    const hiddenMarkup = renderToStaticMarkup(React.createElement(AuthField, {
        id: 'password', name: 'password', label: 'Password', type: 'password', value: 'retained-value',
        autoComplete: 'current-password', onChange() {}, children: hiddenToggle,
    }));

    assert.match(hiddenMarkup, /type="password"/);
    assert.match(hiddenMarkup, /value="retained-value"/);
    assert.match(hiddenMarkup, /type="button"[^>]*aria-label="Show password"[^>]*aria-pressed="false"/);
    assert.match(hiddenMarkup, /focus-visible:ring-2/);
    assert.doesNotMatch(hiddenMarkup, /data-cds-action|cds-tab-active|bg-gradient/);
    hiddenToggle.props.onClick();
    assert.deepEqual(changes, ['toggle']);

    const visibleToggle = PasswordVisibilityToggle({ visible: true, onToggle() {} });
    const visibleMarkup = renderToStaticMarkup(React.createElement(AuthField, {
        id: 'password', name: 'password', label: 'Password', type: 'text', value: 'retained-value',
        autoComplete: 'current-password', onChange() {}, children: visibleToggle,
    }));
    assert.match(visibleMarkup, /type="text"/);
    assert.match(visibleMarkup, /aria-label="Hide password"[^>]*aria-pressed="true"/);
    assert.match(loginSource, /type=\{showPassword \? 'text' : 'password'\}/);
    assert.match(loginSource, /onToggle=\{\(\) => setShowPassword\(value => !value\)\}/);
    assert.match(loginSource, /post\('\/login'\)/);
});
