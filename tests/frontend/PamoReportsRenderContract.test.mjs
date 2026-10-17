import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

const require = createRequire(resolve('package.json'));

function pageHookRuntime({ firstRefValue = null } = {}) {
    const updates = [];
    let cursor = 0;
    let values = [];
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        values[index] = typeof initial === 'function' ? initial() : initial;
        return [values[index], next => {
            values[index] = typeof next === 'function' ? next(values[index]) : next;
            updates.push({ index, value: values[index] });
        }];
    };
    runtime.useRef = initial => {
        const index = cursor++;
        return { current: index === 0 && firstRefValue !== null ? firstRefValue : initial };
    };
    runtime.useEffect = () => {};
    runtime.useMemo = callback => callback();
    return {
        runtime,
        updates,
        beginRender() { cursor = 0; values = []; updates.length = 0; },
    };
}

async function component(name, { hooks = React } = {}) {
    const bundle = await rolldown({
        input: resolve(`resources/js/Pages/PamoReports/${name}.jsx`),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [{
            name: 'pamo-reports-page-render-shims',
            resolveId(source) {
                if (source === '@inertiajs/react') return '\0pamo-inertia-shim';
                if (source === '@/Layouts/AuthenticatedLayout') return '\0pamo-authenticated-layout-shim';
                if (source.endsWith('.css')) return '\0pamo-css-shim';
                if (source === 'pdfjs-dist') return '\0pamo-pdfjs-shim';
                if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0pamo-pdfjs-worker-shim';
            },
            load(id) {
                if (id === '\0pamo-css-shim') return '';
                if (id === '\0pamo-inertia-shim') return `
                    import React from 'react';
                    export const router = { get(...args) { globalThis.__pamoReportsRouterCalls?.push(args); }, visit() {} };
                    export const Link = ({ href, children, preserveState, preserveScroll, ...props }) => React.createElement('a', { href, ...props }, children);
                    export function useForm(initial = {}) {
                        return { data: { ...initial }, errors: {}, processing: false, progress: null,
                            setData() {}, clearErrors() {}, reset() {}, transform() { return this; },
                            post() {}, put() {}, patch() {}, delete() {}, setError() {} };
                    }
                `;
                if (id === '\0pamo-authenticated-layout-shim') return `
                    import React from 'react';
                    export default function AuthenticatedLayout({ children }) {
                        return React.createElement('main', { 'data-test-layout': 'authenticated' }, children);
                    }
                `;
                if (id === '\0pamo-pdfjs-shim') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => { throw new Error("PDF rendering is unavailable in this markup fixture"); };';
                if (id === '\0pamo-pdfjs-worker-shim') return 'export default "/pdfjs-worker-fixture.mjs";';
                if (/\.(png|jpe?g|svg|webp|gif)$/.test(id)) return 'export default "test-image";';
            },
        }],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? hooks : require(id), module, module.exports);
    return module.exports.default || module.exports;
}

const Reports = await component('Index');
const Archives = await component('Archives');
let startedNewDraft = 0;
const reportsHooks = pageHookRuntime({ firstRefValue: { startNew: () => { startedNewDraft += 1; } } });
const InteractiveReports = await component('Index', { hooks: reportsHooks.runtime });

function findElement(root, predicate) {
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

function paginator({ data = [], total = data.length, currentPage = 1, lastPage = 1, query = '' } = {}) {
    const path = '/pamo/reports';
    const queryString = query ? `?${query}` : '';
    const url = page => `${path}${queryString}${queryString ? '&' : '?'}page=${page}`;
    const from = data.length ? ((currentPage - 1) * 25) + 1 : null;
    const to = data.length ? from + data.length - 1 : null;
    return {
        current_page: currentPage,
        data,
        first_page_url: url(1),
        from,
        last_page: lastPage,
        last_page_url: url(lastPage),
        links: [
            { url: currentPage > 1 ? url(currentPage - 1) : null, label: '&laquo; Previous', active: false },
            ...Array.from({ length: lastPage }, (_, index) => ({ url: url(index + 1), label: String(index + 1), active: index + 1 === currentPage })),
            { url: currentPage < lastPage ? url(currentPage + 1) : null, label: 'Next &raquo;', active: false },
        ],
        next_page_url: currentPage < lastPage ? url(currentPage + 1) : null,
        path,
        per_page: 25,
        prev_page_url: currentPage > 1 ? url(currentPage - 1) : null,
        to,
        total,
    };
}

function reportRow(overrides = {}) {
    return {
        id: 'draft-42',
        workflow_key: 'homestay',
        workflow_label: 'Homestay',
        activity_name: 'Training on Homestay Program',
        document_type: 'Final Report',
        reporting_period: 'Quarter 3',
        date_conducted: null,
        date_accomplished: null,
        started_at: '2026-10-12T04:00:00.000Z',
        updated_at: '2026-10-12T04:30:00.000Z',
        forwarded_at: null,
        status: 'Draft',
        lock_version: 1,
        report_payload: { activity_name: 'Training on Homestay Program', reporting_period: 'Quarter 3' },
        mov_file_name: null,
        mov_url: null,
        source_key: null,
        source_submission_id: null,
        source_url: null,
        source_mov_url: null,
        ...overrides,
    };
}

const workflows = [{
    key: 'homestay', label: 'Homestay', source: 'conservation', activities: ['Training on Homestay Program'],
    documents: ['Progress Report', 'Final Report'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3'],
    default_activity: 'Training on Homestay Program', period_field: 'reporting_period', period_label: 'Reporting Period',
}];
const pamoContext = { protected_area: 'Sample Protected Area', pamo_office: 'PAMO Sample', target_office: 'CENRO Baganga', first_recipient: 'CENRO Baganga CDS Focal Person' };
const area = { id: 4, name: 'Sample Protected Area' };

function renderReports({ rows, search = '', workflow = '' }) {
    return renderToStaticMarkup(React.createElement(Reports, {
        workflows,
        rows,
        assignedProtectedArea: area,
        pamoContext,
        filters: { search, workflow, page: rows.current_page },
        pamoWorkspace: true,
    }));
}

test('PAMO Reports empty draft paginator renders an empty state and numeric zero', () => {
    const markup = renderReports({ rows: paginator({ data: [], total: 0 }) });
    assert.match(markup, /No draft reports/);
    assert.ok(markup.includes('0 private drafts'));
    assert.match(markup, /New draft/);
    assert.match(markup, /Search drafts/);
    assert.match(markup, /role="group" aria-label="PAMO search and filters"/);
    assert.match(markup, /<label[^>]*for="pamo-reports-search"[^>]*>Search drafts<\/label>/);
    assert.match(markup, /placeholder="Search drafts"/);
    assert.match(markup, />Filters</);
    assert.doesNotMatch(markup, /Submitted Reports|role="tab"/);
});

test('PAMO Reports renders a populated private draft, aligned toolbar, and no submitted view switch', () => {
    const markup = renderReports({ rows: paginator({ data: [reportRow()], total: 1 }) });
    assert.match(markup, /Training on Homestay Program/);
    assert.match(markup, />1 private draft</);
    assert.match(markup, /Filters/);
    assert.match(markup, /Submit Report/);
    assert.doesNotMatch(markup, /Continue Draft/);
    assert.match(markup, /rounded-xl px-4 py-2 text-xs[^\"]*\">Submit Report<\/button>/);
    assert.match(markup, /tabindex="0" aria-haspopup="dialog"/);
    assert.doesNotMatch(markup, /Submitted Reports|role="tab"/);
});

test('PAMO Reports later page keeps visible records, paginator total, and search/page URLs', () => {
    const second = reportRow({ id: 'draft-27', activity_name: 'Second page activity', report_payload: { activity_name: 'Second page activity' } });
    const rows = paginator({ data: [second], total: 27, currentPage: 2, lastPage: 2, query: 'search=homestay&workflow=homestay' });
    const markup = renderReports({ rows, search: 'homestay', workflow: 'homestay' });
    assert.match(markup, /Second page activity/);
    assert.match(markup, />27 private drafts</);
    assert.match(markup, /href="\/pamo\/reports\?search=homestay&amp;workflow=homestay&amp;page=1"/);
    assert.match(markup, /href="\/pamo\/reports\?search=homestay&amp;workflow=homestay&amp;page=2"/);
    assert.match(markup, /placeholder="Search drafts"[^>]*value="homestay"/);
    assert.match(markup, /Remove Search filter/);
    assert.match(markup, /Clear all/);
});

test('PAMO Reports search, workflow, reset, and New draft callbacks remain connected', () => {
    reportsHooks.beginRender();
    const tree = InteractiveReports({
        workflows,
        rows: paginator({ data: [], total: 0 }),
        assignedProtectedArea: area,
        pamoContext,
        filters: { search: 'forest', workflow: 'homestay' },
        pamoWorkspace: true,
    });
    const toolbar = findElement(tree, element => element.props.searchId === 'pamo-reports-search');
    assert.ok(toolbar);
    assert.equal(toolbar.props.search, 'forest');
    toolbar.props.onSearchChange({ target: { value: 'wildlife' } });
    const workflow = findElement(toolbar.props.children, element => element.props.id === 'pamo-reports-workflow-filter');
    assert.ok(workflow);
    assert.equal(workflow.props.value, 'homestay');
    workflow.props.onChange('conservation');
    assert.deepEqual(reportsHooks.updates.map(update => update.value), ['wildlife', 'conservation']);

    toolbar.props.onClear();
    assert.deepEqual(reportsHooks.updates.slice(-2).map(update => update.value), ['', '']);
    assert.equal(toolbar.props.action.props.children, '＋ New draft');
    toolbar.props.action.props.onClick();
    assert.equal(startedNewDraft, 1);
});

test('PAMO Archives paginator renders its archive record and numeric total', () => {
    const archives = paginator({ data: [{
        id: 19, report: 'Homestay', activity_name: 'Training on Homestay Program', document_type: 'Final Report',
        reporting_period: 'Quarter 3', archived_at: '2026-10-12T05:00:00.000Z', status: 'Recorded · checked Oct 12, 2026',
        file_url: '/pamo/archives/19/file', filename: 'homestay-report.pdf',
    }], total: 2, query: 'search=homestay' });
    const markup = renderToStaticMarkup(React.createElement(Archives, {
        archives, assignedProtectedArea: area, filters: { search: 'homestay' },
    }));
    assert.match(markup, /Archived report copies/);
    assert.match(markup, /Training on Homestay Program/);
    assert.match(markup, />2 retained copies</);
    assert.match(markup, /Open protected copy/);
});
