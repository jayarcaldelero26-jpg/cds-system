import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Compile the real components in memory. No browser, network, or output files.
async function component(name) {
    const bundle = await rolldown({
        input: resolve(`resources/js/Components/SubmissionTracking/${name}.jsx`),
        external: id => id === 'react' || id.startsWith('react/'),
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(createRequire(import.meta.url), module, module.exports);
    return module.exports;
}

const Preview = await component('DocumentPreviewDialog');
const PambProgress = await component('PambMovProgress');
const trackingPage = readFileSync(resolve('resources/js/Pages/SubmissionTracking/Index.jsx'), 'utf8');
const render = row => renderToStaticMarkup(React.createElement(Preview, { open: true, row }));

test('only the protected current document is embedded for supported MIME types', () => {
    for (const mime_type of ['application/pdf', 'image/jpeg', 'image/png']) {
        const markup = render({ current_document: { name: 'synthetic', mime_type, preview_url: '/attachments/aws-report/1/report?preview=1' }, mov_url: '/overview' });
        assert.match(markup, /src="\/attachments\/aws-report\/1\/report\?preview=1"/);
        assert.doesNotMatch(markup, /\/overview/);
        assert.match(markup, mime_type === 'application/pdf' ? /<iframe/ : /<img/);
    }
});

test('unsupported and unverified MIME never embed, even with a PDF filename', () => {
    for (const mime_type of [null, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']) {
        const markup = render({ current_document: { name: 'looks-like.pdf', mime_type, preview_url: '/attachments/source/1/mov?preview=1', download_url: '/attachments/source/1/mov?download=1' } });
        assert.doesNotMatch(markup, /<iframe|<img/);
        assert.match(markup, /Download Current Copy/);
        assert.match(markup, mime_type ? /cannot be previewed/ : /could not be verified/);
    }
});

test('missing current descriptor cannot revive other document URLs', () => {
    const markup = render({ current_document: null, mov_url: '/overview', effective_document: { url: '/archive' }, mov_attachment: { url: '/history', mime_type: 'application/pdf' } });
    assert.doesNotMatch(markup, /<iframe|<img|\/overview|\/archive|\/history|Download Current Copy/);
    assert.match(markup, /No MOV\/report attachment is available/);
});

test('PAMB MOV Review retains milestones and actions without a second overall percentage or bar', () => {
    const markup = renderToStaticMarkup(React.createElement(PambProgress, {
        row: {
            mov_processing: {
                applicable: true,
                status_key: 'ready_for_release',
                percent: 70,
                status_label: 'Reviewed by CENRO CDS Chief - Ready for Release',
                workflow_status: 'Ready for CENRO Records Release',
                milestones: [{ key: 'submitted', label: 'MOV Submitted for Review', complete: true }],
                review_history: [{ event_label: 'Marked Ready for Release', recorded_by: 'Reviewer', recorded_at: '2026-09-28' }],
                turnaround: { label: 'On time', deadline: '2026-09-30' },
            },
            mov_url: '/protected/current-mov',
            pamb_action_flags: { can_release: true },
            cenro_release_applicable: true,
        },
    }));

    assert.match(markup, /MOV Review/);
    assert.match(markup, /Ready for CENRO Records Release/);
    assert.match(markup, /MOV Submitted for Review/);
    assert.match(markup, /CENRO MOV Review History/);
    assert.match(markup, /Release/);
    assert.doesNotMatch(markup, /MOV Processing Progress|70%|role="progressbar"|View MOV|protected\/current-mov/);
});

test('compact rows and Full Details use the normalized routing percentage as their only overall bars', () => {
    assert.match(trackingPage, /routing\.processing_percentage/);
    assert.match(trackingPage, /details\.routing\.processing_percentage/);
    assert.equal([...trackingPage.matchAll(/role="progressbar"/g)].length, 2);
    assert.doesNotMatch(trackingPage, /mov_processing\.percent/);
});
