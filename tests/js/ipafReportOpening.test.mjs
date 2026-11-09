import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { resolve } from 'node:path';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

const server = await createServer({
    configFile: false,
    plugins: [{
        name: 'ipaf-report-opening-inertia-fixture',
        enforce: 'pre',
        resolveId: id => id === '@inertiajs/react' ? '\0ipaf-inertia-fixture' : null,
        load: id => id === '\0ipaf-inertia-fixture' ? `
            import { useState } from 'react';
            export const usePage = () => ({ props: { auth: {
                canCreateTechnicalReports: true, canUpdateTechnicalReports: true, canDeleteTechnicalReports: true,
            } } });
            export const useForm = initial => {
                const [data, setData] = useState(initial);
                return { data, errors: {}, clearErrors() {}, setData };
            };
            export const Link = ({ children }) => children;
            export const Head = () => null;
            export const router = {};
        ` : null,
    }, viteReactInterop(), react()],
    resolve: { alias: { '@': resolve('resources/js') } },
    ssr: { noExternal: ['@inertiajs/react'] },
    server: { middlewareMode: true, hmr: false, ws: false },
    appType: 'custom',
    logLevel: 'error',
});

test.after(() => server.close());

function findElement(tree, matches) {
    if (!React.isValidElement(tree)) return null;
    if (matches(tree)) return tree;
    for (const child of React.Children.toArray(tree.props.children)) {
        const match = findElement(child, matches);
        if (match) return match;
    }
    return null;
}

async function openIpafReport(record, kind = 'revenue') {
    const { default: Ipaf } = await server.ssrLoadModule('/resources/js/Pages/Ipaf/Index.jsx');
    const { default: CrudTable } = await server.ssrLoadModule('/resources/js/Components/Crud/CrudTable.jsx');
    const { default: CrudDetailsModal } = await server.ssrLoadModule('/resources/js/Components/Crud/CrudDetailsModal.jsx');
    const { default: CrudFormModal } = await server.ssrLoadModule('/resources/js/Components/Crud/CrudFormModal.jsx');
    const tracker = findElement(Ipaf({
        filters: { ipaf_tab: kind },
        revenueCollections: { data: [record] },
        managementReports: { data: [record] },
        protectedAreas: [{ id: 21, name: 'Fixture Protected Landscape' }],
        targetOffices: [{ id: 7, name: 'CENRO Mati' }],
        protectedAreasByOffice: { 7: [{ id: 21, name: 'Fixture Protected Landscape' }] },
    }), element => element.props.kind === kind);
    assert.ok(tracker);
    const slots = [];
    const internals = React.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
    const renderTracker = () => {
        let cursor = 0;
        const previous = internals.H;
        internals.H = {
            useState(initial) {
                const index = cursor++;
                if (!(index in slots)) slots[index] = initial;
                return [slots[index], value => { slots[index] = value; }];
            },
            useEffect() {},
        };
        try {
            return tracker.type(tracker.props);
        } finally {
            internals.H = previous;
        }
    };
    findElement(renderTracker(), element => element.type === CrudTable).props.onRowClick(record);
    const details = findElement(renderTracker(), element => element.type === CrudDetailsModal);
    assert.equal(details.props.open, true);
    return {
        details,
        editMarkup(action) {
            action();
            const form = findElement(renderTracker(), element => element.type === CrudFormModal);
            assert.equal(form.props.open, true);
            return renderToStaticMarkup(form);
        },
    };
}

const record = {
    id: 12, protected_area_id: 21, protected_area_name: 'Fixture Protected Landscape',
    target_office: 'CENRO Mati', activity_name: 'Revenue Collection', document_type: 'Progress Report',
    reporting_month: 10, reporting_year: 2026, deadline_submission: '2026-11-10',
    total_collected: '100.00', ipaf_ria: '75.00', sagf: '25.00', mov: null,
    submission_status: 'Pending Submission by CENRO', is_historical: 0,
};

test('opening a revenue report and its edit or attachment form keeps the scoped office and area', async () => {
    const report = await openIpafReport(record);
    const detailsHtml = renderToStaticMarkup(report.details);
    assert.match(detailsHtml, /aria-label="Revenue Collection Details"/);
    assert.match(detailsHtml, /October 2026/);
    assert.match(detailsHtml, /Fixture Protected Landscape/);
    assert.match(detailsHtml, /Not Yet Submitted \/ Missing/);
    assert.doesNotMatch(detailsHtml, />\s*0\s*</);
    assert.match(detailsHtml, /₱100\.00/);
    const attach = findElement(report.details.props.attachments, element => element.props.children === 'Attach MOV');
    assert.ok(attach);
    for (const action of [report.details.props.onEdit, attach.props.onClick]) {
        const html = report.editMarkup(action);
        assert.match(html, /Edit Revenue Collection/);
        assert.match(html, /CENRO Mati/);
        assert.match(html, /Fixture Protected Landscape/);
        assert.match(html, /25th of the reporting month/);
        assert.doesNotMatch(html, /Deadline for Submission to PENRO|ipaf-deadline-submission/);
    }
});

test('completed revenue reports still open without edit, delete, or attach actions', async () => {
    const report = await openIpafReport({ ...record, submission_status: 'Completed' });
    const html = renderToStaticMarkup(report.details);
    assert.match(html, /aria-label="Revenue Collection Details"/);
    assert.doesNotMatch(html, /Edit Details|Delete Record|Attach MOV/);
});

test('a zero collection still displays zero peso amounts without a stray zero under the summary', async () => {
    const report = await openIpafReport({ ...record, total_collected: '0.00', ipaf_ria: '0.00', sagf: '0.00' });
    const html = renderToStaticMarkup(report.details);
    assert.doesNotMatch(html, />\s*0\s*</);
    assert.equal((html.match(/₱0\.00/g) || []).length, 3);
});

test('management details and the edit form display the saved activity and retain actual date fields', async () => {
    const title = 'Protected area fund reconciliation workshop';
    const report = await openIpafReport({ ...record, activity_name: title, date_conducted: '2026-10-05', date_accomplished: '2026-10-06' }, 'management');
    const html = report.editMarkup(report.details.props.onEdit);
    assert.match(renderToStaticMarkup(report.details), new RegExp(title));
    assert.match(html, /for="ipaf-management-activity"[^>]*>Name of Activity</);
    assert.match(html, /name="activity_name"[^>]*value="Protected area fund reconciliation workshop"/);
    assert.match(html, /Date Conducted/);
    assert.match(html, /Date Accomplished/);
});
