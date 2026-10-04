import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { readFile } from 'node:fs/promises';
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

test('recipient coverage action only changes to Cancel for the exact edited mapping', async () => {
    const { coverageRowAction } = await load('Pages/ComplianceAlerts/Index.jsx');
    assert.equal(coverageRowAction(12, 12), 'cancel');
    assert.equal(coverageRowAction(12, '12'), 'cancel');
    assert.equal(coverageRowAction(13, 12), 'edit');
    assert.equal(coverageRowAction(12, null), 'edit');
});

test('Compliance Alerts retains its operational scopes without a duplicate history or confirmation UI', async () => {
    const { complianceOperationalTabs } = await load('Pages/ComplianceAlerts/Index.jsx');
    assert.deepEqual(complianceOperationalTabs, [['protected_area', 'Protected Area'], ['engp', 'ENGP / Development']]);
    const source = await readFile(new URL('../..//resources/js/Pages/ComplianceAlerts/Index.jsx', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /Compliance Alert History|tab === 'history'|Pending Records Verification|Records Confirmation History/);
    assert.doesNotMatch(source, /compliance-alerts\.(confirm|unconfirm)|Confirm Records Receipt|Revoke Records Confirmation/);
});

test('operational overdue details present Submission Tracking receipt state without a second confirmation action', async () => {
    const source = await readFile(new URL('../..//resources/js/Pages/ComplianceAlerts/Index.jsx', import.meta.url), 'utf8');
    const operationalDetailsStart = source.indexOf('<CrudDetailsModal open={Boolean(selected)}');
    const recipientDetailsStart = source.indexOf('<CrudDetailsModal open={Boolean(selectedRecipient)}', operationalDetailsStart);
    assert.ok(operationalDetailsStart >= 0 && recipientDetailsStart > operationalDetailsStart);

    const operationalDetails = source.slice(operationalDetailsStart, recipientDetailsStart);
    assert.match(operationalDetails, /report\.records_confirmed \? `Received by PENRO Records on/);
    assert.doesNotMatch(operationalDetails, /Confirm Records Receipt|Open Records verification|Revoke Records Confirmation/);
    assert.doesNotMatch(source, /compliance-alerts\.(confirm|unconfirm)/);
});

test('canonical routing correction remains; the duplicate admin disclosure action is absent', async () => {
    const source = await readFile(new URL('../..//resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
    assert.ok(source.includes('canCorrectSubmissionRouting && details && <Button'));
    assert.ok(source.includes('>Correct routing</Button>'));
    assert.doesNotMatch(source, /<Button[^>]*>Correct Routing Record<\/Button>/);
    assert.ok(source.includes('canAdminRoutingOverride && <Button'));
    assert.ok(source.includes('>Admin Override</Button>'));
});

test('Protected Area create-only card switch leaves edit mode on its prior sections', async () => {
    const { protectedAreaCreateCardSurface } = await load('Pages/ProtectedAreas/Form.jsx');
    assert.equal(protectedAreaCreateCardSurface(false), true);
    assert.equal(protectedAreaCreateCardSurface(true), false);
    const { default: FormSection } = await load('Components/FormSection.jsx');
    const createMarkup = renderToStaticMarkup(React.createElement(FormSection, { title: 'Additional Notes', cardSurface: protectedAreaCreateCardSurface(false), children: 'Form fields' }));
    const editMarkup = renderToStaticMarkup(React.createElement(FormSection, { title: 'Additional Notes', cardSurface: protectedAreaCreateCardSurface(true), children: 'Form fields' }));
    assert.match(createMarkup, /cds-card-surface/);
    assert.doesNotMatch(editMarkup, /cds-card-surface/);
});

test('record delete confirmation uses the shared danger dialog with compact Cancel and Delete buttons', async () => {
    const { default: ConfirmDialog } = await load('Components/ConfirmDialog.jsx');
    const html = renderToStaticMarkup(React.createElement(ConfirmDialog, {
        open: true, variant: 'danger', title: 'Delete protected area?',
        message: 'Record-specific warning.', confirmLabel: 'Delete', onConfirm() {}, onCancel() {},
    }));
    assert.match(html, /bg-red-100 text-red-600/);
    assert.match(html, />Cancel<\/button>/);
    assert.match(html, />Delete<\/button>/);
    assert.equal((html.match(/type="button"/g) || []).length, 2);
});

test('Protected Area delete entry points both use danger confirmation and the exact Delete label', async () => {
    const editSource = await readFile(new URL('../..//resources/js/Pages/ProtectedAreas/Form.jsx', import.meta.url), 'utf8');
    const detailsSource = await readFile(new URL('../..//resources/js/Pages/ProtectedAreas/Index.jsx', import.meta.url), 'utf8');
    assert.match(editSource, /<ConfirmDialog[^>]*variant="danger"[^>]*confirmLabel="Delete"/);
    assert.match(detailsSource, /<ConfirmDialog[\s\S]*?variant="danger"[\s\S]*?confirmLabel="Delete"/);
    assert.match(editSource, /deleteRequestInFlight\.current/);
    assert.match(editSource, /router\.delete\(`\/protected-areas\/\$\{protectedArea\.id\}`/);
});
