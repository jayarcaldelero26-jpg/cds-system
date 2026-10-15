import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Archives.jsx', import.meta.url), 'utf8');

test('Archives page renders separate verified, pending, and released receipt workspaces', () => {
    assert.match(page, /Verified Archives/);
    assert.match(page, /Awaiting Archive/);
    assert.match(page, /Awaiting Final Receipt/);
    assert.match(page, /Receive Final MOVs from Regional Office/);
    assert.match(page, /Add to Archive/);
    assert.match(page, /Retry Archive/);
    assert.match(page, /View Archive/);
    assert.match(page, /LaravelPaginationLinks links=\{/);
    assert.match(page, /FilePreviewPanel file=\{\{ \.\.\.file, type: file\.mime_type, protected: true \}\}/);
});

test('final receipt and archive dialogs use shared modal controls and actual date/time input', () => {
    assert.equal((page.match(/<CrudFormModal open=/g) || []).length, 3);
    assert.match(page, /type="datetime-local"/);
    assert.match(page, /final-archives\.receipts\.store/);
    assert.match(page, /final-archives\.store/);
    assert.match(page, /final-archives\.retry/);
    assert.match(page, /file\.preview_url/);
    assert.match(page, /file\.download_url/);
});

test('signature confirmation follows the complete multi-file selection and its private preview', () => {
    assert.match(page, /signature_confirmation: false/);
    assert.match(page, /multiple required disabled=\{archiveForm\.processing\}/);
    assert.match(page, /signature_confirmation: false/);
    assert.match(page, /complete returned file set and confirm its signatures are complete/);
    assert.match(page, /does not cryptographically validate signatures/);
    assert.match(page, /URL\.createObjectURL\(file\)/);
    assert.doesNotMatch(page, /maxFiles=\{1\}|multiple=\{false\}/);
});
