import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;
const load = async (path) => {
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

test('real single-select component renders one selected value without checkbox options', async () => {
    const { default: ScopedOptionSelect, ScopedSelectOption } = await load('Components/Form/ScopedOptionSelect.jsx');
    const html = renderToStaticMarkup(React.createElement(ScopedOptionSelect, {
        id: 'office', label: 'Office', value: 12,
        options: [{ id: 12, label: 'CENRO Baganga' }, { id: 13, label: 'PENRO' }],
    }));
    assert.match(html, /role="combobox"/);
    assert.match(html, /value="CENRO Baganga"/);
    assert.doesNotMatch(html, /type="checkbox"/);
    const option = renderToStaticMarkup(React.createElement(ScopedSelectOption, { option: { label: 'PENRO' }, selected: true }));
    assert.match(option, /role="option" aria-selected="true"/);
});

test('shared native filter select keeps its selected string value and does not render checkboxes', async () => {
    const { default: FloatingSelect } = await load('Components/Form/FloatingSelect.jsx');
    const html = renderToStaticMarkup(React.createElement(FloatingSelect, {
        label: 'Status', value: 'active', onChange: () => {},
        children: [React.createElement('option', { key: 'all', value: '' }, 'All Statuses'), React.createElement('option', { key: 'active', value: 'active' }, 'Active')],
    }));
    assert.match(html, /<select[^>]*value="active"|<select[^>]*>/);
    assert.match(html, /<option value="active" selected="">Active<\/option>/);
    assert.doesNotMatch(html, /type="checkbox"/);
});

test('Calendar form select integration keeps its controlled string value and option contract', async () => {
    const { CalendarSelect } = await load('Pages/Calendar/Index.jsx');
    const html = renderToStaticMarkup(React.createElement(CalendarSelect, {
        value: 'OFFICE', onChange: () => {}, required: true,
        children: [React.createElement('option', { key: 'national', value: 'NATIONAL' }, 'National'), React.createElement('option', { key: 'office', value: 'OFFICE' }, 'Office')],
    }));
    assert.match(html, /role="combobox"/);
    assert.match(html, /aria-required="true"/);
    assert.match(html, /<option value="OFFICE" selected="">Office<\/option>/);
    assert.doesNotMatch(html, /type="checkbox"/);
});

test('Protected Area compact field sizing leaves multiline notes multiline', async () => {
    const { default: FormField } = await load('Components/FormField.jsx');
    const { default: FloatingTextarea } = await load('Components/Form/FloatingTextarea.jsx');
    const text = renderToStaticMarkup(React.createElement(FormField, { id: 'area-name', label: 'Protected Area Name', size: 'sm', value: 'Sample', onChange: () => {} }));
    const notes = renderToStaticMarkup(React.createElement(FloatingTextarea, { id: 'description', label: 'Description', rows: 4, value: 'Long text', onChange: () => {} }));
    assert.match(text, /h-10 px-3 py-2 text-sm leading-5/);
    assert.match(notes, /<textarea[^>]*rows="4"/);
    assert.match(notes, /min-h-28/);
});

test('incoming action categories keep filter semantics, selected state, and callbacks outside action styling', async () => {
    const { IncomingActionFilters } = await load('Pages/SubmissionTracking/Index.jsx');
    const callbacks = [];
    const props = {
        tabs: ['receive', 'forward'],
        labels: { receive: 'Receive', forward: 'Forward' },
        selected: 'receive',
        onChange: value => callbacks.push(value),
    };
    const markup = renderToStaticMarkup(React.createElement(IncomingActionFilters, props));
    assert.match(markup, /role="group" aria-label="Incoming action filters"/);
    assert.match(markup, /aria-pressed="true"[^>]*>Receive<\/button>/);
    assert.match(markup, /aria-pressed="false"[^>]*>All actions<\/button>/);
    assert.doesNotMatch(markup, /data-cds-action/);
    assert.match(markup, /focus-visible:ring-2/);

    const tree = IncomingActionFilters(props);
    const visit = node => {
        if (!React.isValidElement(node)) return;
        if (node.type === 'button') node.props.onClick();
        React.Children.forEach(node.props.children, visit);
    };
    visit(tree);
    assert.deepEqual(callbacks, [null, 'receive', 'forward']);
});

test('Executive report chips describe applied filters and preserve query parameter values', async () => {
    const { makeFilterChips, reportFilterParams } = await load('Pages/Reports/Index.jsx');
    const chips = makeFilterChips({ year: 2026, domain: 'pa', period: 'q1', office: 'CENRO A', protected_area_id: '12', workflow: 'monthly', status: 'Received' }, {
        periods: [{ value: 'q1', label: 'Quarter 1' }], protected_areas: [{ id: 12, name: 'Forest PA' }], families: [{ value: 'monthly', label: 'Monthly Report' }],
    });
    assert.deepEqual(chips.map(({ key, value }) => [key, value]), [['year', '2026'], ['period', 'Quarter 1'], ['domain', 'PA Monitoring'], ['office', 'CENRO A'], ['protected_area_id', 'Forest PA'], ['workflow', 'Monthly Report'], ['status', 'Received']]);
    assert.deepEqual(reportFilterParams({ year: '2026', domain: 'pa', period: '', office: 'CENRO A', protected_area_id: '12', workflow: '', status: 'Received' }), {
        year: '2026', domain: 'pa', period: undefined, office: 'CENRO A', protected_area_id: '12', workflow: undefined, status: 'Received',
    });
});

test('Module Management real filter forwards the original value and preserves query parameters', async () => {
    const { FilterSelect, buildModuleFilterParams } = await load('Pages/Admin/Settings/ModuleManagement.jsx');
    let received;
    const filter = FilterSelect({ value: '12', label: 'All Offices', options: [{ value: '12', label: 'CENRO' }], onChange: value => { received = value; } });
    const select = filter.props.children;
    select.props.onChange({ target: { value: '12' } });
    assert.equal(received, '12');
    assert.deepEqual(buildModuleFilterParams({ search: 'forest', page: 3, status: 'active' }, 'office_id', received), {
        search: 'forest', page: 3, status: 'active', office_id: '12',
    });
    assert.deepEqual(buildModuleFilterParams({ office_id: 12 }, 'office_id', ''), { office_id: undefined });
});

test('real reusable multi-select renders selected labels and option checkboxes', async () => {
    const { default: MultiSelectFilter, MultiSelectOption } = await load('Components/Form/MultiSelectFilter.jsx');
    const html = renderToStaticMarkup(React.createElement(MultiSelectFilter, {
        id: 'offices', label: 'Offices', value: ['cenro', 'penro'],
        options: [{ value: 'cenro', label: 'CENRO' }, { value: 'penro', label: 'PENRO' }],
    }));
    assert.match(html, /CENRO, PENRO/);
    const option = renderToStaticMarkup(React.createElement(MultiSelectOption, { option: { label: 'CENRO' }, checked: true }));
    assert.match(option, /role="option" aria-selected="true"/);
    assert.match(option, /type="checkbox"/);
    assert.match(option, /checked=""/);
});

test('warning actions use the warning variant while preserving compact button sizing', async () => {
    const { default: Button } = await load('Components/Button.jsx');
    const html = renderToStaticMarkup(React.createElement(Button, { variant: 'warning', size: 'compact' }, 'Deactivate'));
    assert.match(html, /data-cds-action-variant="warning"/);
    assert.match(html, /Deactivate/);
    assert.match(html, /min-h-0/);
});

test('Module Management renders Edit and Deactivate with matching compact size and warning color', async () => {
    const { ModuleDefinitionActions } = await load('Pages/Admin/Settings/ModuleManagement.jsx');
    const html = renderToStaticMarkup(React.createElement(ModuleDefinitionActions, {
        definition: { is_active: true }, onEdit: () => {}, onToggle: () => {},
    }));
    const actions = [...html.matchAll(/<button[^>]+>/g)].map(match => match[0]);
    assert.equal(actions.length, 2);
    assert.match(html, /inline-flex flex-wrap items-center gap-2/);
    assert.match(actions[0], /cds-compact-action/);
    assert.match(actions[1], /cds-compact-action/);
    assert.match(actions[1], /data-cds-action-variant="warning"/);
    assert.match(html, />Edit<\/button>/);
    assert.match(html, />Deactivate<\/button>/);
});

test('compliance tab integration exposes selected state and preserves tab selection callback values', async () => {
    const { default: ComplianceTabList } = await load('Components/Compliance/ComplianceTabList.jsx');
    let selected;
    const element = React.createElement(ComplianceTabList, {
        tabs: [['protected_area', 'Protected Area'], ['engp', 'Development / ENGP']],
        value: 'protected_area', onChange: value => { selected = value; }, label: 'Recipient mapping type',
    });
    const html = renderToStaticMarkup(element);
    assert.match(html, /role="tablist" aria-label="Recipient mapping type"/);
    assert.match(html, /role="tab" aria-selected="true"/);
    assert.match(html, /cds-compliance-tab recipient-scope-tab/);
    const tabs = element.type(element.props).props.children;
    tabs[1].props.onClick();
    assert.equal(selected, 'engp');
});

test('details dialogs render a footer close action without a redundant header close control', async () => {
    const { default: CrudDetailsModal } = await load('Components/Crud/CrudDetailsModal.jsx');
    const html = renderToStaticMarkup(React.createElement(CrudDetailsModal, {
        open: true, title: 'Example details', onClose: () => {}, closeLabel: 'Close details',
        children: React.createElement('p', null, 'Read only'),
    }));
    assert.match(html, /Close details/);
    assert.equal((html.match(/aria-label="Close modal"/g) || []).length, 0);
});

test('real form modal with Cancel retains its top close control', async () => {
    const { default: CrudFormModal } = await load('Components/Crud/CrudFormModal.jsx');
    const html = renderToStaticMarkup(React.createElement(CrudFormModal, {
        open: true, title: 'Edit report', onClose: () => {}, onSubmit: () => {},
        children: React.createElement('p', null, 'Form fields'),
    }));
    assert.match(html, /aria-label="Close modal"/);
    assert.match(html, />Cancel<\/button>/);
});

test('shared data table preserves row activation, keyboard access, and supplied pagination', async () => {
    const { default: CrudTable } = await load('Components/Crud/CrudTable.jsx');
    const selected = [];
    const page = React.createElement(CrudTable, {
        columns: [{ key: 'name', label: 'Name' }],
        rows: [{ id: 7, name: 'Forest Area' }],
        onRowClick: row => selected.push(row.id),
        pagination: React.createElement('nav', null, 'Page 2 of 4'),
    });
    const html = renderToStaticMarkup(page);
    assert.match(html, /Forest Area/);
    assert.match(html, /Page 2 of 4/);
    const rendered = page.type(page.props);
    const findRow = node => {
        if (!node || typeof node !== 'object') return null;
        if (Array.isArray(node)) return node.map(findRow).find(Boolean) || null;
        if (node.type === 'tr' && node.props?.onClick) return node;
        return findRow(node.props?.children);
    };
    const row = findRow(rendered);
    assert.ok(row, 'the data row is interactive');
    row.props.onClick({ type: 'click' });
    row.props.onKeyDown({ type: 'keydown', key: 'Enter', preventDefault() {} });
    assert.deepEqual(selected, [7, 7]);
});

test('Destination Coverage labels an absent recipient while preserving real recipient values', async () => {
    const { recipientCellValue } = await load('Pages/ComplianceAlerts/Index.jsx');
    assert.equal(recipientCellValue(null), 'No recipient assigned');
    assert.equal(recipientCellValue('records@example.test'), 'records@example.test');
});

test('report modal card context applies shared card treatment to genuine section surfaces', async () => {
    const { default: CrudDetailsModal } = await load('Components/Crud/CrudDetailsModal.jsx');
    const { default: CrudSection } = await load('Components/Crud/CrudSection.jsx');
    const html = renderToStaticMarkup(React.createElement(CrudDetailsModal, {
        open: true, title: 'Report details', onClose() {},
        children: React.createElement(CrudSection, { title: 'General Information' }, React.createElement('p', null, 'Report content')),
    }));
    assert.match(html, /cds-card-surface[^\"]*rounded-xl border/);
    assert.match(html, /Report content/);
});

test('PAMB review-history action remains canonical and the timeline does not duplicate it', async () => {
    const { default: PambRoutingTimeline } = await load('Components/SubmissionTracking/PambRoutingTimeline.jsx');
    const html = renderToStaticMarkup(React.createElement(PambRoutingTimeline, { row: { pamb_routing_applicable: false } }));
    assert.doesNotMatch(html, /View Review History/);
});

test('populated PAMB meeting timeline exposes the pending Office forwarding action', async () => {
    const { default: PambRoutingTimeline } = await load('Components/SubmissionTracking/PambRoutingTimeline.jsx');
    for (const workflow of ['regular_pamb', 'special_pamb', 'twc_meetings']) {
        const html = renderToStaticMarkup(React.createElement(PambRoutingTimeline, {
            row: {
                workflow_key: workflow,
                pamb_routing_applicable: true,
                routing_timeline: [{
                    key: 'forwarded_records_to_penro',
                    stage_key: 'forwarded_records_to_penro',
                    label: 'Forwarded to Office of the PENRO',
                    status: 'current',
                    held_at: 'PENRO Records',
                    destination: 'Office of the PENRO',
                    occurred_at: null,
                    recorded_by: null,
                    actor_office: null,
                    is_internal: true,
                    can_record: true,
                    action_label: 'Record Forwarding to Office of the PENRO',
                }],
            },
            actions: [],
            onRecord: () => {},
        }));

        assert.match(html, /Forwarded to Office of the PENRO/);
        assert.match(html, /<button[^>]*type="button"[^>]*>Forward<\/button>/);
    }
});

test('projected focal custody action renders in sidebar and Full Details while MOV release stays separately scoped', async () => {
    const { SubmissionDetailsPanel, IncomingActionFilters } = await load('Pages/SubmissionTracking/Index.jsx');
    const { default: DocumentRoutingTimeline } = await load('Components/SubmissionTracking/DocumentRoutingTimeline.jsx');
    const { default: PambMovActions } = await load('Components/SubmissionTracking/PambMovActions.jsx');
    const { default: PambMovProgress } = await load('Components/SubmissionTracking/PambMovProgress.jsx');
    const action = { key: 'forward_to_cenro_chief', label: 'forwarded', action_label: 'Forward to CENRO Chief', to: 'transit_to_cenro_chief' };
    const row = {
        source: 'conservation', source_id: 97, module: 'Regular PAMB', workflow_key: 'regular_pamb',
        target_office: 'CENRO Mati', canonical_custody_applicable: true, can_transition: true,
        routing: {
            profile_key: 'canonical_conservation', profile_label: 'Conservation routing',
            current_stage: 'cenro_preparation', responsible_user_category: 'CENRO CDS Focal Person',
            responsible_office: 'CENRO Mati', next_expected_action: 'Forward to CENRO Chief',
            actions: [action], timeline: [{ key: 'cenro_preparation', label: 'CENRO CDS Focal Person', status: 'current' }],
        },
        mov_processing: { applicable: true, status_key: 'ready_for_release', status_label: 'Ready for Release', workflow_status: 'Ready for Release', milestones: [] },
        pamb_action_flags: { can_submit: false, can_review: false, can_release: false },
        current_document: { name: 'Approved MOV.pdf', can_preview: true }, mov_url: '/storage/approved-mov.pdf',
    };
    const actionCalls = [];
    const previewCalls = [];
    const sidebar = React.createElement(SubmissionDetailsPanel, {
        row, context: {}, onAction: (next) => actionCalls.push(next), onPreview: (next) => previewCalls.push(next),
    });
    const sidebarHtml = renderToStaticMarkup(sidebar);
    const detailsHtml = renderToStaticMarkup(React.createElement(DocumentRoutingTimeline, { row, onAction: (next) => actionCalls.push(next) }));
    assert.match(sidebarHtml, />Forward</);
    assert.match(sidebarHtml, /Preview/);
    assert.match(sidebarHtml, /data-cds-action-variant="cancel"[^>]*>Preview<\/button>/);
    assert.match(detailsHtml, />Forward</);
    assert.match(detailsHtml, /Canonical Routing Progress/);

    const panelTree = SubmissionDetailsPanel({ row, onAction: (next) => actionCalls.push(next), onPreview: (next) => previewCalls.push(next) });
    const walk = (node) => {
        if (!React.isValidElement(node)) return;
        if (node.props.onClick) {
            if (node.props.children === 'Forward') node.props.onClick();
            if (node.props.children === 'Preview') node.props.onClick();
        }
        React.Children.forEach(node.props.children, walk);
    };
    walk(panelTree);
    assert.deepEqual(actionCalls, [action]);
    assert.deepEqual(previewCalls, [row]);

    const outgoingRow = { ...row, can_transition: false, routing: { ...row.routing, actions: [] }, current_document: { ...row.current_document, can_preview: false } };
    const outgoingHtml = renderToStaticMarkup(React.createElement(SubmissionDetailsPanel, { row: outgoingRow, onPreview: () => assert.fail('Preview callback must not be offered') }));
    assert.match(outgoingHtml, /Preview unavailable for this account/);
    assert.doesNotMatch(outgoingHtml, />Preview</);

    const noPermissionRow = { ...row, can_transition: false };
    assert.doesNotMatch(renderToStaticMarkup(React.createElement(DocumentRoutingTimeline, { row: noPermissionRow })), />Forward</);
    const chiefMov = { ...row, mov_processing: { ...row.mov_processing, status_key: 'submitted_for_review' }, pamb_action_flags: { can_review: true } };
    const movHtml = renderToStaticMarkup(React.createElement(PambMovActions, { row: chiefMov, onReview() {} }));
    assert.match(movHtml, /Ready for Release/);
    assert.match(movHtml, /Needs Correction/);
    assert.match(movHtml, /data-cds-action-variant="warning"[^>]*>Needs Correction<\/button>/);
    assert.doesNotMatch(renderToStaticMarkup(React.createElement(PambMovActions, { row, onRelease() {} })), /Release/);

    const awaitingReceipt = {
        ...chiefMov,
        routing: { ...row.routing, current_stage: 'transit_to_cenro_chief', next_expected_action: 'Receive', actions: [{ key: 'receive_cenro_chief', label: 'Receive', action_label: 'Receive' }] },
    };
    const awaitingSidebar = renderToStaticMarkup(React.createElement(SubmissionDetailsPanel, { row: awaitingReceipt, onAction() {}, onReviewMov() {} }));
    const receivePosition = awaitingSidebar.indexOf('>Receive</button>');
    const reviewPosition = awaitingSidebar.indexOf('MOV review and submission');
    assert.ok(receivePosition >= 0 && reviewPosition > receivePosition, 'custody Receive is rendered before the separate MOV review controls');
    assert.ok(awaitingSidebar.indexOf('>Ready for Release</button>') > reviewPosition);
    assert.ok(awaitingSidebar.indexOf('>Needs Correction</button>') > reviewPosition);

    const fullDetailsActions = renderToStaticMarkup(React.createElement(React.Fragment, null,
        React.createElement(DocumentRoutingTimeline, { row: awaitingReceipt, onAction() {} }),
        React.createElement(PambMovProgress, { row: awaitingReceipt, hideReleaseAction: true, onReview() {} }),
    ));
    assert.ok(fullDetailsActions.indexOf('>Receive</button>') < fullDetailsActions.indexOf('>Ready for Release</button>'));

    const filterValues = [];
    const filterTree = IncomingActionFilters({ tabs: ['receive', 'forward'], labels: { receive: 'Receive', forward: 'Forward' }, selected: 'forward', onChange: value => filterValues.push(value) });
    const filtersHtml = renderToStaticMarkup(filterTree);
    assert.match(filtersHtml, /cds-tab-active/);
    React.Children.forEach(filterTree.props.children, button => button?.props?.onClick?.());
    assert.deepEqual(filterValues, [null, 'receive', 'forward']);
});

test('Full Details renders the existing routing percentage with or without a document and respects preview access', async () => {
    const { SubmissionTrackingDetailsProgressCard } = await load('Pages/SubmissionTracking/Index.jsx');
    const details = {
        routing_complete: false,
        routing: { processing_percentage: 68 },
        mov_processing: { applicable: true, percent: 100 },
    };
    const withoutDocument = renderToStaticMarkup(React.createElement(SubmissionTrackingDetailsProgressCard, { details }));
    assert.match(withoutDocument, /aria-label="Routing progress and current official document"/);
    assert.match(withoutDocument, /role="progressbar"[^>]*aria-valuenow="68"/);
    assert.match(withoutDocument, />68%<\/span>/);
    assert.doesNotMatch(withoutDocument, /Current Official Document/);

    const withDocument = renderToStaticMarkup(React.createElement(SubmissionTrackingDetailsProgressCard, {
        details: { ...details, current_document: { name: 'Current official report.pdf', can_preview: true } },
        onPreview: () => {},
    }));
    assert.match(withDocument, />68%<\/span>/);
    assert.match(withDocument, /Current Official Document/);
    assert.match(withDocument, />Preview Current Document<\/button>/);
    assert.match(withDocument, /data-cds-action-variant="cancel"[^>]*>Preview Current Document<\/button>/);
    assert.doesNotMatch(withDocument, /100%/);
});

test('protected preview explains projected denial without offering download or a loading state', async () => {
    const { default: DocumentPreviewDialog } = await load('Components/SubmissionTracking/DocumentPreviewDialog.jsx');
    const html = renderToStaticMarkup(React.createElement(DocumentPreviewDialog, {
        open: true,
        row: { source: 'bms', id: 42, current_document: { name: 'Current report.pdf', preview_url: '/attachments/bms-report/42/mov?preview=1', download_url: '/attachments/bms-report/42/mov', mime_type: 'application/pdf', can_preview: false } },
        onClose() {},
    }));
    assert.match(html, /Your current account is not authorized to access this document/);
    assert.doesNotMatch(html, /Loading document preview|Download Current Copy|Retry Preview/);
    assert.match(html, />Close Preview<\/button>/);
});

test('Create Module close control is a neutral accessible icon button and retains its callback', async () => {
    const { ModuleForm } = await load('Pages/Admin/Settings/ModuleManagement.jsx');
    let closed = false;
    const values = { name: '', program_area: '', module_type: 'regular_target', reporting_frequency: 'monthly', plan_duration_years: '', deadline_mode: 'none', default_deadline_days: '', allow_deadline_override: false, description: '', is_active: true };
    const element = React.createElement(ModuleForm, { form: { data: values, errors: {}, processing: false, setData() {} }, editing: null, programAreas: [], frequencies: [], onClose: () => { closed = true; }, onSubmit() {} });
    const html = renderToStaticMarkup(element);
    assert.match(html, /aria-label="Close module form"/);
    assert.match(html, /type="button"/);
    assert.match(html, /focus-visible:ring-2/);
    const close = element.type(element.props).props.children.props.children[0].props.children[1];
    close.props.onClick();
    assert.equal(closed, true);
});

test('Protected Area detail actions render matching compact Edit and Delete controls with icons', async () => {
    const { ProtectedAreaDetailActions } = await load('Pages/ProtectedAreas/Index.jsx');
    const tree = ProtectedAreaDetailActions({ areaId: 8, canEdit: true, canDelete: true, onDelete() {} });
    const html = renderToStaticMarkup(tree);
    const buttons = [...html.matchAll(/<(?:a|button)[^>]+>/g)].map(match => match[0]);
    assert.equal(buttons.length, 2);
    for (const control of buttons) assert.match(control, /cds-compact-action[^>]*min-w-\[92px\]/);
    assert.equal(tree.props.children[0].props.children[0].props.icon, 'lucide:pencil');
    assert.equal(tree.props.children[1].props.children[0].props.icon, 'lucide:trash-2');
});

test('shared filter toolbar count is based on supplied applied chips, not draft values', async () => {
    const { default: FilterToolbar } = await load('Components/Form/FilterToolbar.jsx');
    const { makeFilterChips } = await load('Pages/Reports/Index.jsx');
    const applied = { year: '2026', domain: 'all', period: '', office: '', protected_area_id: '', workflow: '', status: '' };
    const before = makeFilterChips(applied, {});
    const draftOnly = { ...applied, status: 'Received' };
    assert.equal(before.length, 1);
    assert.equal(makeFilterChips(applied, {}).length, before.length);
    assert.equal(makeFilterChips(draftOnly, {}).length, 2);
    const html = renderToStaticMarkup(React.createElement(FilterToolbar, { chips: before, children: React.createElement('div', null, 'filters') }));
    assert.match(html, /Filters.*1 selected/);
});

test('utility icon controls stay outside shared action styling and keep their accessible label', async () => {
    const { default: UtilityIconButton } = await load('Components/UtilityIconButton.jsx');
    const html = renderToStaticMarkup(React.createElement(UtilityIconButton, { 'aria-label': 'Notifications', children: React.createElement('span', null, 'bell') }));
    assert.match(html, /aria-label="Notifications"/);
    assert.doesNotMatch(html, /data-cds-action/);
    assert.match(html, /focus-visible:ring-2/);
});

test('optional User Details assignments are omitted while category codes get display labels', async () => {
    const { UserOrganizationDetails, userCategoryLabel } = await load('Pages/Admin/Users/Index.jsx');
    const emptyHtml = renderToStaticMarkup(React.createElement(UserOrganizationDetails, { user: { effective_category: 'PENRO_TSD_CHIEF', unit_assignment: '', office_designated: null, protected_area_name: undefined } }));
    assert.match(emptyHtml, /PENRO TSD Chief/);
    assert.doesNotMatch(emptyHtml, />Unit</);
    assert.doesNotMatch(emptyHtml, />Office</);
    assert.equal(userCategoryLabel('OFFICE_OF_THE_PENRO'), 'Office of the PENRO');
    assert.equal(userCategoryLabel('PENRO_RECORDS'), 'PENRO Records');
});
