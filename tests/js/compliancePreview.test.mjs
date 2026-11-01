import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import {
    compliancePreviewErrorMessage,
    NO_PREVIEW_REPORTS_MESSAGE,
    PREVIEW_ERROR_MESSAGE,
    readCompliancePreviewResponse,
} from '../../resources/js/Utils/compliancePreview.mjs';

test('compliance preview accepts the real JSON contract and preserves the useful no-reports message', async () => {
    const preview = { subject: 'Subject', html: '<p>Report preview</p>', recipient: { email: 'focal@example.test' } };
    assert.deepEqual(await readCompliancePreviewResponse(new Response(JSON.stringify(preview), { status: 200 })), preview);

    await assert.rejects(
        readCompliancePreviewResponse(new Response(JSON.stringify({ message: NO_PREVIEW_REPORTS_MESSAGE }), { status: 422 })),
        { message: NO_PREVIEW_REPORTS_MESSAGE },
    );
    assert.equal(compliancePreviewErrorMessage(new Error(NO_PREVIEW_REPORTS_MESSAGE)), NO_PREVIEW_REPORTS_MESSAGE);
});

test('the actual Compliance Alerts page delegates preview response parsing to the shared safe helper', async () => {
    const source = await readFile(resolve('resources/js/Pages/ComplianceAlerts/Index.jsx'), 'utf8');
    assert.match(source, /import \{ compliancePreviewErrorMessage, readCompliancePreviewResponse \} from ['"]@\/Utils\/compliancePreview\.mjs['"]/);
    assert.match(source, /readCompliancePreviewResponse\(response\)/);
    assert.doesNotMatch(source, /readPreviewResponse|console\.error\([^\n]*(payload|body)/);
});

test('malformed or failed preview responses never log or surface raw recipient and memorandum content', async () => {
    const original = { error: console.error, warn: console.warn, log: console.log };
    const logged = [];
    console.error = (...values) => logged.push(values);
    console.warn = (...values) => logged.push(values);
    console.log = (...values) => logged.push(values);

    try {
        const privatePayload = { recipient: { email: 'focal@example.test' }, html: '<p>Confidential report</p>' };
        await assert.rejects(
            readCompliancePreviewResponse(new Response(JSON.stringify(privatePayload), { status: 500 })),
            { message: PREVIEW_ERROR_MESSAGE },
        );
        await assert.rejects(
            readCompliancePreviewResponse(new Response('<html>private provider detail</html>', { status: 200 })),
            { message: PREVIEW_ERROR_MESSAGE },
        );
        await assert.rejects(
            readCompliancePreviewResponse(new Response('', { status: 200 })),
            { message: PREVIEW_ERROR_MESSAGE },
        );
        await assert.rejects(
            readCompliancePreviewResponse(new Response(JSON.stringify({ subject: 7, html: '<p>private content</p>' }), { status: 200 })),
            { message: PREVIEW_ERROR_MESSAGE },
        );

        assert.equal(compliancePreviewErrorMessage({ response: { data: privatePayload } }), PREVIEW_ERROR_MESSAGE);
        assert.deepEqual(logged, []);
    } finally {
        console.error = original.error;
        console.warn = original.warn;
        console.log = original.log;
    }
});
