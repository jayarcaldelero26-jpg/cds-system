import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;

async function load(path) {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: {
            alias: [
                { find: /^@inertiajs\/react$/, replacement: resolve('tests/js/helpers/inertiaSettingsStub.jsx') },
                { find: /^@\/Layouts\/AuthenticatedLayout$/, replacement: resolve('tests/js/helpers/authenticatedLayoutStub.jsx') },
                { find: '@', replacement: resolve('resources/js') },
            ],
        },
        server: { middlewareMode: true },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule(`/resources/js/${path}`);
}

test.after(async () => { await server?.close(); });

const settings = {
    available: true,
    version: 1,
    office_penro_enabled: true,
    penro_tsd_chief_enabled: true,
    saved_at: null,
    saved_by: null,
    reason: null,
};

async function renderRoutingWorkflow(auth) {
    const { default: RoutingWorkflow } = await load('Pages/Admin/Settings/RoutingWorkflow.jsx');
    globalThis.__settingsNavigationPage = { props: { auth } };
    try {
        return renderToStaticMarkup(React.createElement(RoutingWorkflow, { settings, canUpdate: true }));
    } finally {
        delete globalThis.__settingsNavigationPage;
    }
}

test('Routing Workflow navigation renders Storage and Diagnostics for an authorized Super Admin', async () => {
    const html = await renderRoutingWorkflow({
        user: { roles: ['Super Admin'] },
        canViewModuleManagement: true,
        canManageComplianceAlerts: true,
        canViewRoutingWorkflow: true,
        canViewStorage: true,
        canViewSystemDiagnostics: true,
    });

    for (const path of [
        '/settings/module-management',
        '/settings/compliance-alerts',
        '/settings/routing-workflow',
        '/settings/storage',
        '/settings/system-diagnostics',
    ]) assert.match(html, new RegExp(`href="${path}"`));

    assert.match(html, /href="\/settings\/routing-workflow"[^>]*aria-current="page"/);
    assert.match(html, /Report Routing/);
    assert.doesNotMatch(html, /Routing Workflow/);
});

test('Routing Workflow navigation shows authorized CDS Admin links but hides Storage', async () => {
    const html = await renderRoutingWorkflow({
        user: { roles: ['CDS Admin'] },
        canViewModuleManagement: true,
        canManageComplianceAlerts: true,
        canViewRoutingWorkflow: true,
        canViewStorage: false,
        canViewSystemDiagnostics: true,
    });

    for (const path of [
        '/settings/module-management',
        '/settings/compliance-alerts',
        '/settings/routing-workflow',
        '/settings/system-diagnostics',
    ]) assert.match(html, new RegExp(`href="${path}"`));

    assert.doesNotMatch(html, /href="\/settings\/storage"/);
    assert.match(html, /href="\/settings\/routing-workflow"[^>]*aria-current="page"/);
    assert.match(html, /Report Routing/);
    assert.doesNotMatch(html, /Routing Workflow/);
});
