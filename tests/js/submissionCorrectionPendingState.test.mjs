import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const tracking = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
const pamo = await readFile(new URL('../../resources/js/Pages/PamoNativeReports/Index.jsx', import.meta.url), 'utf8');

test('MOV submit pending state is scoped by report/action and clears on request finish or synchronous failure', () => {
    assert.match(tracking, /const actionKey = `\$\{submissionKey\(row\) \|\| 'report'\}:mov-submit`/);
    assert.match(tracking, /movSubmissionInFlight\.current\.begin\(actionKey\)/);
    assert.match(tracking, /onFinish: finish/);
    assert.match(tracking, /catch \{\s*finish\(\);\s*return false;/);
});

test('PAMO corrected resubmission pending state is scoped by report/action and clears on all request endings', () => {
    assert.match(pamo, /`\$\{workflow\.source\}:\$\{pendingResubmission\.id\}:\$\{pendingResubmission\.resubmit_action \|\| 'resubmit'\}`/);
    assert.match(pamo, /resubmissionInFlight\.current\.begin\(actionKey\)/);
    assert.match(pamo, /onSuccess: \(\) => setPendingResubmission\(null\)/);
    assert.match(pamo, /onError:/);
    assert.match(pamo, /onHttpException:/);
    assert.match(pamo, /onNetworkError:/);
    assert.match(pamo, /onFinish: finish/);
    assert.match(pamo, /catch \{\s*setResubmissionError\([\s\S]*?finish\(\)/);
});
