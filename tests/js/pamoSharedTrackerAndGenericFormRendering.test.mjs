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
        server: { middlewareMode: true, hmr: false },
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
    const missing = renderToStaticMarkup(React.createElement(PamoSavedDraftsTable, {
        workflow: { key: 'homestay', label: 'Homestay' },
        drafts: [{ id: 'draft-2', status: 'draft', started_at: null, report_payload: {} }],
    }));
    assert.match(missing, /<td[^>]*>—<\/td>/);
});

test('rendered Regular, Special, and TWC generic PAMO fields include configured periods and conducted-date ranges', async () => {
    const { default: PamoGenericSubmissionFields } = await load('Components/PamoGenericSubmissionFields.jsx');
    const workspace = await readFile(resolve('resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8');
    const fieldsSource = await readFile(resolve('resources/js/Components/PamoGenericSubmissionFields.jsx'), 'utf8');
    assert.match(workspace, /genericForm && <PamoGenericSubmissionFields/);
    assert.match(fieldsSource, /onClick=\{onAddRange\}/);
    assert.match(fieldsSource, /onClick=\{\(\) => onRemoveRange\(index\)\}/);
    assert.match(workspace, /saveLabel=\{inCorrection \? 'Save correction' : submitMode \? 'Submit Report' : 'Save Draft'\}/);
    assert.match(workspace, /Submit Report from the draft row when ready/);

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
        assert.match(html, /<button type="button" data-cds-action="true" data-cds-action-variant="primary" class="[^\"]*min-h-10[^\"]*rounded-ui[^\"]*px-4[^\"]*">\+ Add Date<\/button>/);
        assert.match(html, /<button type="button" data-cds-action="true" data-cds-action-variant="danger" class="[^\"]*min-h-10[^\"]*rounded-ui[^\"]*px-4[^\"]*">Remove<\/button>/);
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
