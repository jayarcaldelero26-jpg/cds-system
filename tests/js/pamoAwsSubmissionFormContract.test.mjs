import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const file = path => fileURLToPath(new URL(path, import.meta.url));

test('PAMO and the original AWS tracker select the same native submission fields and action contract', async () => {
    const [shared, workspace, tracker, entry, controller] = await Promise.all([
        readFile(file('../../resources/js/Components/Reports/AwsSubmissionFields.jsx'), 'utf8'),
        readFile(file('../../resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8'),
        readFile(file('../../resources/js/Pages/Bms/ReportSubmissionTracker.jsx'), 'utf8'),
        readFile(file('../../resources/js/Pages/AWS/AwsReportSubmissionTracker.jsx'), 'utf8'),
        readFile(file('../../app/Http/Controllers/AwsController.php'), 'utf8'),
    ]);

    assert.ok(workspace.includes("workflow.form === 'aws_submission'"));
    assert.ok(workspace.includes('<AwsSubmissionFields form={form} workflowConfig={workflow}'));
    assert.ok(tracker.includes("workflowConfig?.key === 'aws' ? <AwsSubmissionFields"));
    assert.ok(entry.includes("key: 'aws'"));
    assert.ok(entry.includes("store: route('aws.store')"));
    for (const field of ['activity_name', 'document_type', 'quarter', 'date_conducted', 'monitoring_period_start', 'monitoring_period_end', 'date_accomplished', 'remarks']) {
        if (field === 'quarter') {
            assert.ok(entry.includes("period_field: 'quarter'"));
            continue;
        }
        assert.ok(shared.includes(`form.data.${field}`) || shared.includes(`form.data[${field}]`), `shared report component should use ${field}`);
    }
    for (const section of ['General / Report Information', 'Coverage Period', 'Compliance Basis', 'Report Attachment & Remarks']) {
        assert.ok(shared.includes(section), `shared report component should contain ${section}`);
    }
    for (const rejected of ['pamo-aws-status', 'pamo-aws-station-name', 'pamo-aws-location', 'Monthly</option>', 'Recommendation & Remarks']) {
        assert.ok(!workspace.includes(rejected), `PAMO draft should not render the station form field ${rejected}`);
    }
    assert.ok(controller.includes('public function validationRules(bool $fileRequired'));
    assert.ok(controller.includes("$validated = $this->deriveCanonicalPeriod($validated)"));
    assert.ok(controller.includes("$this->validateMonitoringPeriod($validated)"));
});
