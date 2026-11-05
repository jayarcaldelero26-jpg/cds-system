import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
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
        server: { middlewareMode: true, hmr: false, ws: false },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule(`/resources/js/${path}`);
};

test.after(async () => { await server?.close(); });

function genericForm(workflow) {
    const values = {
        activity_name: workflow.default_activity,
        document_type: '',
        reporting_period: '',
        date_conducted: '',
        date_conducted_ranges: [{ from: '', to: '' }],
        date_accomplished: '',
    };
    return {
        data: values,
        errors: {},
        setData(key, value) {
            if (typeof key === 'object') Object.assign(values, key);
            else values[key] = value;
        },
    };
}

function findElement(root, predicate) {
    if (Array.isArray(root)) {
        for (const child of root) {
            const found = findElement(child, predicate);
            if (found) return found;
        }
        return null;
    }
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

function assertDateConductedAccessibility(markup, triggerCount, { required = false } = {}) {
    const group = markup.match(/<fieldset\b[^>]*data-date-conducted-group[^>]*>[\s\S]*?<\/fieldset>/)?.[0];
    assert.ok(group, 'the Date Conducted fieldset is rendered');

    const ids = [...group.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
    const idSet = new Set(ids);
    assert.equal(idSet.size, ids.length, 'helper, error, and picker ids are unique within the group');

    const hint = group.match(/<p\b(?=[^>]*data-date-conducted-guidance)([^>]*)>([\s\S]*?)<\/p>/);
    assert.ok(hint, 'the group guidance is visible inside the Date Conducted fieldset');
    const hintId = hint[1].match(/\bid="([^"]+)"/)?.[1];
    assert.ok(hintId);
    assert.equal(hint[2].replace(/<[^>]*>/g, '').trim(), 'Choose today or an earlier date (Philippines time).');
    assert.equal((group.match(/Choose today or an earlier date \(Philippines time\)\./g) || []).length, 1,
        'the guidance renders once per group, not once per row');

    const descriptionLists = [...group.matchAll(/aria-describedby="([^"]+)"/g)]
        .map(match => match[1].split(/\s+/).filter(Boolean));
    assert.equal(descriptionLists.length, triggerCount + 1, 'the group and each date trigger expose descriptions');
    for (const references of descriptionLists) {
        for (const id of references) assert.ok(idSet.has(id), `aria-describedby target ${id} exists in the group`);
    }

    const triggers = [...group.matchAll(/<button\b(?=[^>]*aria-haspopup="dialog")([^>]*)>/g)]
        .map(match => match[1]);
    assert.equal(triggers.length, triggerCount, 'each date row renders one range-picker trigger');
    const triggerDescriptions = triggers.map(attributes => {
        const describedBy = attributes.match(/\baria-describedby="([^"]+)"/)?.[1];
        assert.ok(describedBy, 'each date trigger has an accessible description');
        const references = describedBy.split(/\s+/).filter(Boolean);
        assert.ok(references.includes(hintId), 'each date trigger references the shared guidance');
        if (required) assert.match(attributes, /\baria-required="true"/);
        return references;
    });

    return { group, hintId, triggerDescriptions };
}

test('populated Homestay saved-draft row renders the Manila preparation timestamp through the actual shared table', async () => {
    const { default: PamoSavedDraftsTable } = await load('Components/PamoSavedDraftsTable.jsx');
    const html = renderToStaticMarkup(React.createElement(PamoSavedDraftsTable, {
        workflow: { key: 'homestay', label: 'Homestay', period_field: 'reporting_period', period_label: 'Reporting Period' },
        drafts: [{ id: 'draft-1', status: 'draft', started_at: '2026-10-12T04:00:00.000Z', report_payload: { activity_name: 'Training on Homestay Program', reporting_period: 'Quarter 3' } }],
    }));
    assert.match(html, /Training on Homestay Program/);
    assert.match(html, /Quarter 3/);
    assert.match(html, /Oct 12, 2026, 12:00 PM/);
    assert.match(html, /Preparation started/);
    const draft = { id: 'draft-2', status: 'draft', started_at: null, report_payload: { activity_name: 'Homestay draft missing optional fields' } };
    const forwarded = { id: 'draft-3', status: 'forwarded', started_at: null, report_payload: { activity_name: 'Forwarded Homestay report' } };
    let clickedDraftId = '';
    const onRowClick = row => { clickedDraftId = row.id; };
    const table = PamoSavedDraftsTable({
        workflow: { key: 'homestay', label: 'Homestay' },
        drafts: [draft, forwarded],
        onRowClick,
    });
    const missing = renderToStaticMarkup(React.createElement(table.type, table.props));
    assert.match(missing, /<td[^>]*>Not recorded<\/td>/, 'the shared Manila formatter supplies the missing timestamp label');
    assert.match(missing, /<td[^>]*>Not provided<\/td>/, 'an absent reporting period retains its explicit fallback');
    assert.match(missing, /<td[^>]*>Draft<\/td>/, 'the editable row remains identified as a draft');
    const draftRow = missing.match(/<tr\b([^>]*)>(?:(?!<tr\b)[\s\S])*Homestay draft missing optional fields(?:(?!<tr\b)[\s\S])*?<\/tr>/)?.[1] || '';
    const forwardedRow = missing.match(/<tr\b([^>]*)>(?:(?!<tr\b)[\s\S])*Forwarded Homestay report(?:(?!<tr\b)[\s\S])*?<\/tr>/)?.[1] || '';
    assert.match(draftRow, /\btabindex="0"/);
    assert.match(draftRow, /\baria-haspopup="dialog"/);
    assert.doesNotMatch(forwardedRow, /\btabindex=|\baria-haspopup=/, 'forwarded rows do not expose draft editing controls');
    table.props.onRowClick(draft);
    assert.equal(clickedDraftId, draft.id, 'clicking an active draft keeps its edit action');
    table.props.onRowClick(forwarded);
    assert.equal(clickedDraftId, draft.id, 'forwarded rows remain inert');
});

test('rendered Regular, Special, and TWC generic PAMO fields include configured periods and conducted-date ranges', async () => {
    const [{ default: PamoGenericSubmissionFields }, DateConductedRangesFieldModule] = await Promise.all([
        load('Components/PamoGenericSubmissionFields.jsx'),
        load('Components/Reports/DateConductedRangesField.jsx'),
    ]);
    const workspace = await readFile(resolve('resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8');
    const fieldsSource = await readFile(resolve('resources/js/Components/PamoGenericSubmissionFields.jsx'), 'utf8');
    const { createDateConductedRowKeyManager } = DateConductedRangesFieldModule;
    assert.match(workspace, /genericForm && <PamoGenericSubmissionFields/);
    assert.match(fieldsSource, /<DateConductedRangesField/);
    const standardFields = workspace.match(/\{standardForm && <CrudSection title="General \/ Report Information">([\s\S]*?)<\/CrudSection>\}/)?.[1];
    assert.ok(standardFields, 'the active PAMO standard report variant remains wired');
    assert.match(standardFields, /<DateConductedRangesField/);
    assert.match(standardFields, /onRangeChange=\{updateRange\}/);
    assert.match(standardFields, /onRemoveRange=\{index => form\.setData\('date_conducted_ranges'/);
    assert.doesNotMatch(standardFields, /key=\{index\}/, 'standard PAMO rows do not recycle index keys');
    assert.match(workspace, /saveLabel=\{inCorrection \? 'Save correction' : submitMode \? 'Submit Report' : 'Save Draft'\}/);
    assert.match(workspace, /Submit Report from the draft row when ready/);

    const keyManager = createDateConductedRowKeyManager('range-test');
    const originalKeys = keyManager.getKeys(3);
    const afterMiddleRemoval = keyManager.remove(1);
    assert.deepEqual(afterMiddleRemoval, [originalKeys[0], originalKeys[2]], 'removing the middle row preserves the identity of the following picker');
    keyManager.add();
    assert.deepEqual(keyManager.getKeys(3), [originalKeys[0], originalKeys[2], 'range-test-row-3'], 'adding a row does not recycle a removed row key');

    const form = {
        data: { date_conducted_ranges: [{ from: '2026-08-01', to: '2026-08-02' }, { from: '2026-09-01', to: '2026-09-02' }] },
        setData(field, value) {
            assert.equal(field, 'date_conducted_ranges');
            this.data[field] = value;
        },
    };
    const workspaceSource = await readFile(resolve('resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8');
    const trackerSource = await readFile(resolve('resources/js/Pages/Bms/ReportSubmissionTracker.jsx'), 'utf8');
    assert.match(workspaceSource, /const updateRange = \(index, value\) => form\.setData\('date_conducted_ranges', form\.data\.date_conducted_ranges\.map\(/);
    assert.match(workspaceSource, /onAddRange=\{\(\) => form\.setData\('date_conducted_ranges', \[\.\.\.form\.data\.date_conducted_ranges, emptyDateConductedRange\(\)\]\)\}/);
    assert.match(workspaceSource, /onRemoveRange=\{index => form\.setData\('date_conducted_ranges', form\.data\.date_conducted_ranges\.filter\(\(_, at\) => at !== index\)\)\}/);
    assert.match(trackerSource, /const updateDateConductedRange = \(index, value\) => form\.setData\('date_conducted_ranges', \(form\.data\.date_conducted_ranges \|\| \[\]\)\.map\(/);
    assert.match(trackerSource, /const removeDateConductedRange = \(index\) => \{ const next = \(form\.data\.date_conducted_ranges \|\| \[\]\)\.filter\(/);

    const internals = React.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
    const previousDispatcher = internals.H;
    const previousWindow = globalThis.window;
    let hookId = 0;
    globalThis.window = { requestAnimationFrame: callback => callback() };
    try {
        internals.H = {
            useId: () => `date-range-fixture-${hookId++}`,
            useRef: value => ({ current: value }),
        };
        const rangeField = DateConductedRangesFieldModule.default({
            rowSetKey: 'callback-fixture',
            ranges: form.data.date_conducted_ranges,
            onRangeChange(index, value) {
                form.setData('date_conducted_ranges', form.data.date_conducted_ranges.map((range, at) => at === index
                    ? { from: value?.from || '', to: value?.to || '' }
                    : range));
            },
            onAddRange() {
                form.setData('date_conducted_ranges', [...form.data.date_conducted_ranges, { from: '', to: '' }]);
            },
            onRemoveRange(index) {
                form.setData('date_conducted_ranges', form.data.date_conducted_ranges.filter((_, at) => at !== index));
            },
        });
        const fieldChildren = React.Children.toArray(rangeField.props.children);
        const list = fieldChildren.find(child => child.props?.['data-date-conducted-list']);
        const rows = React.Children.toArray(list.props.children);
        const firstRow = React.Children.toArray(rows[0].props.children);
        const secondRow = React.Children.toArray(rows[1].props.children);
        const firstChange = { from: '2026-08-03', to: '2026-08-05' };
        const secondChange = { from: '2026-09-04', to: '2026-09-07' };
        firstRow[0].props.onChange(firstChange);
        secondRow[0].props.onChange(secondChange);
        assert.deepEqual(form.data.date_conducted_ranges, [firstChange, secondChange],
            'each visible picker forwards its row index/value into the current date array');

        secondRow[1].props.onClick();
        assert.deepEqual(form.data.date_conducted_ranges, [firstChange],
            'removing the second row removes only its matching date range');
        const addButton = fieldChildren.at(-1);
        addButton.props.onClick();
        assert.deepEqual(form.data.date_conducted_ranges, [firstChange, { from: '', to: '' }],
            'adding a row appends one blank date range while retaining existing values');
    } finally {
        internals.H = previousDispatcher;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }

    const multiRangeHtml = renderToStaticMarkup(React.createElement(DateConductedRangesFieldModule.default, {
        rowSetKey: 'saved-draft-4',
        ranges: [{ from: '2026-09-01', to: '2026-09-02' }, { from: '', to: '' }, { from: '2026-09-10', to: '2026-09-11' }],
        required: true,
        groupError: 'Add at least one valid date range.',
        rangeError: (_, index) => index === 1 ? 'Choose a start and end date.' : '',
        onRangeChange() {}, onAddRange() {}, onRemoveRange() {},
    }));
    const multiRange = assertDateConductedAccessibility(multiRangeHtml, 3, { required: true });
    const alerts = [...multiRange.group.matchAll(/<p\b([^>]*)role="alert"([^>]*)>([\s\S]*?)<\/p>/g)];
    assert.equal(alerts.length, 2, 'the row validation and group validation each render once');
    const rowError = alerts.find(match => match[3] === 'Choose a start and end date.');
    const groupError = alerts.find(match => match[3] === 'Add at least one valid date range.');
    assert.ok(rowError && groupError);
    const rowErrorId = rowError[1].match(/\bid="([^"]+)"/)?.[1];
    const groupErrorId = groupError[1].match(/\bid="([^"]+)"/)?.[1];
    assert.ok(rowErrorId && groupErrorId);
    assert.ok(multiRange.triggerDescriptions.every(references => references.includes(groupErrorId)),
        'the group error is described by each date trigger');
    assert.deepEqual(multiRange.triggerDescriptions.map(references => references.includes(rowErrorId)), [false, true, false],
        'only the matching date row references its field error');

    for (const workflow of [
        { key: 'regular_pamb', label: 'Regular PAMB Meetings', activities: ['Regular PAMB'], documents: ['Minutes', 'Reso', 'Report'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], default_activity: 'Regular PAMB', date_conducted_required: true, date_accomplished_required: false },
        { key: 'special_pamb', label: 'Special PAMB Meetings', activities: ['Special PAMB'], documents: ['Minutes', 'Reso'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], default_activity: 'Special PAMB', date_conducted_required: true, date_accomplished_required: false },
        { key: 'twc_meetings', label: 'TWC Meetings', activities: ['TWC Meeting'], documents: ['Report', 'Minutes'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], default_activity: 'TWC Meeting', date_conducted_required: true, date_accomplished_required: false },
    ]) {
        const periodOptions = workflow.periods.map(period => ({ value: period, label: period }));
        const form = genericForm(workflow);
        const html = renderToStaticMarkup(React.createElement(PamoGenericSubmissionFields, {
            workflow,
            form,
            rangeEnabled: true,
            periodField: 'reporting_period',
            periodOptions,
            onActivityChange() {}, onPeriodChange() {}, onDateConductedChange() {}, onDateAccomplishedChange() {}, onRangeChange() {}, onAddRange() {}, onRemoveRange() {},
            fieldError: () => null,
        }));
        assert.match(html, new RegExp(workflow.default_activity));
        assert.match(html, /Type of Document/);
        assert.match(html, /Reporting Period/);
        assert.match(html, /Date Conducted/);
        assert.match(html, /Date Accomplished/);
        assert.match(html, /Minutes|Report/);
        assert.match(html, /Quarter 4/);
        assert.match(html, /<button type="button" data-cds-action="true" data-cds-action-variant="primary" data-cds-size="compact" class="[^\"]*min-h-8[^\"]*px-2\.5[^\"]*">\+ Add Date<\/button>/);
        assert.match(html, /<button type="button" data-cds-action="true" data-cds-action-variant="danger" data-cds-size="compact" class="[^\"]*min-h-8[^\"]*px-2\.5[^\"]*">Remove<\/button>/);
        const dateGroup = html.match(/<fieldset[^>]*data-date-conducted-group[^>]*>[\s\S]*?<\/fieldset>/)?.[0];
        assert.ok(dateGroup, 'the repeated date controls stay inside one semantic group');
        assertDateConductedAccessibility(dateGroup, 1, { required: true });
    }
});

test('rendered generic Homestay fields use its source activity, document options, period, and configured conducted-date ranges', async () => {
    const { default: PamoGenericSubmissionFields } = await load('Components/PamoGenericSubmissionFields.jsx');
    const workflow = {
        key: 'homestay', label: 'Homestay', activities: ['Training on Homestay Program'],
        documents: ['Progress Report', 'Final Report'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3'],
        default_activity: 'Training on Homestay Program', period_field: 'reporting_period', period_label: 'Reporting Period',
    };
    const form = genericForm(workflow);
    const html = renderToStaticMarkup(React.createElement(PamoGenericSubmissionFields, {
        workflow, form, rangeEnabled: true, periodField: 'reporting_period',
        periodOptions: workflow.periods.map(period => ({ value: period, label: period })),
        onActivityChange() {}, onPeriodChange() {}, onDateConductedChange() {}, onDateAccomplishedChange() {}, onRangeChange() {}, onAddRange() {}, onRemoveRange() {},
        fieldError: () => null,
    }));
    assert.match(html, /Training on Homestay Program/);
    assert.match(html, /Progress Report/);
    assert.match(html, /Final Report/);
    assert.match(html, /Quarter 3/);
    assert.match(html, /Date Conducted/);
    assert.match(html, /Date Accomplished/);
    assert.match(html, /Date 1/);
    assert.match(html, /Add Date/);
});

test('single-catalog, multi-catalog, and custom PAMO workflows all keep activity titles as text', async () => {
    const { default: PamoGenericSubmissionFields } = await load('Components/PamoGenericSubmissionFields.jsx');
    const cepa = {
        key: 'cepa_plan', label: 'CEPA Plan',
        activities: [
            'CEPA Plan preparation (Analysis/Stocktaking)',
            'CEPA Plan preparation (Branding)',
            'CEPA Plan preparation (Identification of strategies)',
            'CEPA Plan preparation (Action Planning)',
            'CEPA Plan preparation (Writing the Communication Plan)',
            'Submission of Final CEPA Plan',
        ],
        documents: ['Progress Report', 'Final Report'],
        default_activity: 'CEPA Plan preparation (Analysis/Stocktaking)',
        activity_documents: {
            'CEPA Plan preparation (Analysis/Stocktaking)': ['Progress Report'],
            'Submission of Final CEPA Plan': ['Final Report'],
        },
    };
    const restoration = {
        key: 'restoration_plan_5_year', label: '5 Year Restoration Plan Preparation',
        activities: ['Preparation of 5-Year Restoration Plan'],
        documents: ['Progress Report', 'Final Report'],
        default_activity: 'Preparation of 5-Year Restoration Plan',
    };
    const customWorkflow = {
        key: 'owner_defined_activity', activities: ['Suggested planning session', 'Suggested field visit'],
        allow_custom_activity: true, documents: ['Progress Report'],
    };

    for (const workflow of [restoration, cepa, customWorkflow]) {
        for (const draftMode of [true, false]) {
            const form = genericForm(workflow);
            form.data.activity_name = 'Owner supplied activity title';
            form.data.document_type = workflow.documents.at(-1);
            let changed;
            const tree = PamoGenericSubmissionFields({
                workflow, form, draftMode, rangeEnabled: false,
                periodField: 'reporting_period', periodOptions: [], onActivityChange(value) { changed = value; },
                onPeriodChange() {}, onDateConductedChange() {}, onDateAccomplishedChange() {},
                onRangeChange() {}, onAddRange() {}, onRemoveRange() {}, fieldError: () => null,
            });
            const activityField = findElement(tree, element => element.props.id === 'pamo-draft-activity');
            assert.equal(activityField.type.name, 'FloatingInput', `${workflow.key} renders a free-text title in ${draftMode ? 'draft' : 'submit'} mode`);
            assert.equal(activityField.props.label, 'Name of Activity');
            assert.equal(activityField.props.value, 'Owner supplied activity title');
            assert.equal(activityField.props.required, !draftMode);
            const dateField = findElement(tree, element => element.props.id === 'pamo-draft-date-conducted');
            assert.equal(dateField?.type.name, 'DatePicker', 'single-date workflows retain the DatePicker fallback');
            assert.equal(dateField.props.actualDate, true, 'single-date workflows retain the Manila actual-date restriction');
            activityField.props.onChange({ target: { value: 'Restoration Plan Workshop' } });
            assert.equal(changed, 'Restoration Plan Workshop', `${workflow.key} sends the event's scalar value`);
            const documentField = findElement(tree, element => element.props.id === 'pamo-draft-document');
            assert.equal(documentField.type.name, 'FloatingSelect', 'document type remains a coded workflow field');
            assert.deepEqual(React.Children.toArray(documentField.props.children).slice(1).map(option => option.props.value), workflow.documents,
                'the document options follow the workflow contract and do not depend on the title text');
        }
    }
});

test('rendered PAMO draft modals leave report content optional while submission modals retain field requirements', async () => {
    const [CrudFormModalModule, PamoGenericSubmissionFieldsModule, AwsSubmissionFieldsModule] = await Promise.all([
        load('Components/Crud/CrudFormModal.jsx'),
        load('Components/PamoGenericSubmissionFields.jsx'),
        load('Components/Reports/AwsSubmissionFields.jsx'),
    ]);
    const CrudFormModal = CrudFormModalModule.default;
    const PamoGenericSubmissionFields = PamoGenericSubmissionFieldsModule.default;
    const AwsSubmissionFields = AwsSubmissionFieldsModule.default;
    const genericWorkflow = {
        key: 'regular_pamb', label: 'Regular PAMB Meetings', activities: ['Regular PAMB'],
        documents: ['Minutes', 'Reso', 'Report'], periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
        activity_required: true, document_type_required: false, period_required: false,
        date_conducted_required: true, date_accomplished_required: false,
    };
    const emptyForm = {
        data: {
            activity_name: '', document_type: '', reporting_period: '', semester: '', quarter: '',
            reporting_month: '', reporting_year: '', total_collected: '', deadline_submission: '',
            monitoring_period_start: '', monitoring_period_end: '', date_conducted: '',
            date_conducted_ranges: [], date_accomplished: '', remarks: '', mov: null,
        },
        errors: {},
        setData() {},
    };
    const renderModal = (fields, saveLabel) => renderToStaticMarkup(React.createElement(CrudFormModal, {
        open: true, mode: 'create', title: 'New draft report', onClose() {}, onSubmit(event) { event.preventDefault(); },
        processing: false, canSave: true, saveLabel,
    }, fields));
    const genericProps = {
        workflow: genericWorkflow, form: emptyForm, draftMode: true, rangeEnabled: false,
        periodField: 'reporting_period', periodOptions: genericWorkflow.periods.map(value => ({ value, label: value })),
        onActivityChange() {}, onPeriodChange() {}, onDateConductedChange() {}, onDateAccomplishedChange() {},
        onRangeChange() {}, onAddRange() {}, onRemoveRange() {}, fieldError: () => null,
    };
    const genericDraft = renderModal(React.createElement(PamoGenericSubmissionFields, genericProps), 'Save Draft');
    assert.match(genericDraft, /Name of Activity/);
    assert.match(genericDraft, /Date Conducted/);
    assert.match(genericDraft, /Save Draft/);
    assert.doesNotMatch(genericDraft, /aria-required="true"|\srequired(?:="")?(?:\s|>)/);
    const saveDraftButton = genericDraft.match(/<button[^>]*>Save Draft<\/button>/)?.[0];
    assert.ok(saveDraftButton, 'the empty draft modal renders its Save Draft button');
    assert.doesNotMatch(saveDraftButton, /\sdisabled(?:\s|=|>)/);

    const genericSubmission = renderModal(React.createElement(PamoGenericSubmissionFields, { ...genericProps, draftMode: false }), 'Submit Report');
    assert.match(genericSubmission, /aria-required="true"/);
    assert.match(genericSubmission, />Submit Report<\/button>/);

    const awsWorkflow = {
        key: 'aws', label: 'Automated Weather Station', form: 'aws_submission', documents: ['Progress Report', 'Final Report'],
        activity_required: true, document_type_required: true, period_required: true,
        period_field: 'quarter', period_label: 'Reporting Period',
        period_options: [1, 2, 3, 4].map(value => ({ value, label: `Quarter ${value}` })),
        date_conducted_required: true, date_accomplished_required: false, coverage_period: true,
    };
    const awsBaseProps = {
        form: emptyForm, workflowConfig: awsWorkflow, pamoContext: { protected_area: 'PAMO APL', target_office: 'CENRO Baganga' },
        attachmentField: 'mov', savedFile: null, fileRequired: true, maxSizeBytes: 10 * 1024 * 1024,
    };
    const awsDraft = renderModal(React.createElement(AwsSubmissionFields, { ...awsBaseProps, draftMode: true }), 'Save Draft');
    assert.match(awsDraft, /Report Attachment \/ Document/);
    assert.doesNotMatch(awsDraft, /aria-required="true"|\srequired(?:="")?(?:\s|>)/);
    assert.doesNotMatch(awsDraft, /Report Attachment \/ Document \*/);
    assert.ok(awsDraft.match(/<button[^>]*>Save Draft<\/button>/), 'the empty AWS draft modal keeps Save Draft available');

    const awsSubmission = renderModal(React.createElement(AwsSubmissionFields, awsBaseProps), 'Submit Report');
    assert.match(awsSubmission, /aria-required="true"/);
    assert.match(awsSubmission, /Report Attachment \/ Document \*/);

    const directAws = renderModal(React.createElement(AwsSubmissionFields, {
        ...awsBaseProps, pamoContext: null, targetOffices: [], protectedAreasByOffice: {},
    }), 'Save Report');
    assert.match(directAws, /Target Office/);
    assert.match(directAws, /Name of PA/);
    assert.match(directAws, /aria-required="true"/);
});

test('Forward serialization carries the current Homestay modal date values and uses the same serializer as Save', async () => {
    const { createPamoDraftFormData, serializePamoDraftPayload } = await load('Components/PamoPambDraftWorkspace.jsx');
    const workspace = await readFile(resolve('resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8');
    const formState = {
        activity_name: 'Training on Homestay Program',
        document_type: 'Final Report',
        reporting_period: 'Quarter 3',
        date_conducted_ranges: [{ from: '2026-09-21', to: '2026-09-25' }],
        date_accomplished: '2026-10-13',
        remarks: 'Current modal values submitted with Forward.',
        mov: null,
        lock_version: 1,
    };
    const payload = serializePamoDraftPayload(formState, { awsForm: false, periodField: 'reporting_period', rangeEnabled: true });
    const newDraft = createPamoDraftFormData();

    for (const field of ['activity_name', 'document_type', 'reporting_period', 'semester', 'quarter', 'reporting_month', 'reporting_year', 'total_collected', 'deadline_submission', 'monitoring_period_start', 'monitoring_period_end', 'date_conducted', 'date_accomplished', 'remarks']) {
        assert.equal(newDraft[field], '', `new PAMO drafts start with blank ${field}`);
    }
    assert.deepEqual(newDraft.date_conducted_ranges, []);
    assert.equal(newDraft.mov, null);
    assert.deepEqual(payload.date_conducted_ranges, [{ from: '2026-09-21', to: '2026-09-25' }]);
    assert.equal(payload.date_accomplished, '2026-10-13');
    assert.equal(payload.reporting_period, 'Quarter 3');
    assert.match(workspace, /forwardForm\.transform\(\(\) => \(\{ \.\.\.serializeCurrentDraft\(form\.data\), lock_version: selected\.lock_version \}\)\)/);
    assert.match(workspace, /pamo-drafts\.forward'.*forceFormData: true/s);
});
