import test from 'node:test';
import assert from 'node:assert/strict';
import { inlinePreviewType, loadProtectedPreview, PreviewLoadError, startProtectedPreview } from '../../resources/js/Utils/protectedDocumentPreview.mjs';

const baseUrl = 'https://cds.test/submission-tracking';
const previewUrl = '/attachments/conservation-report/17/mov?preview=1';
const pdf = () => new Response('%PDF-1.7\nfixture', { headers: { 'content-type': 'application/pdf' } });
const load = (response, options = {}) => loadProtectedPreview(previewUrl, { baseUrl, fetchImpl: async () => response, ...options });

test('fetch uses the same protected source/record URL, session credentials, no cache and no redirect following', async () => {
    const controller = new AbortController();
    const result = await load(pdf(), { signal: controller.signal, fetchImpl: async (url, options) => {
        assert.equal(url, 'https://cds.test' + previewUrl);
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.signal, controller.signal);
        assert.equal(options.headers['X-Inertia'], undefined);
        return pdf();
    } });
    assert.equal(result.type, 'application/pdf');
    assert.match(await result.blob.text(), /^%PDF-1\.7/);
});

for (const [status, code] of [[401, 'session'], [419, 'session'], [403, 'forbidden'], [404, 'missing'], [500, 'response']]) {
    test(`HTTP ${status} is an honest ${code} state and cannot become a preview`, async () => {
        await assert.rejects(load(new Response('<html>Access Restricted</html>', { status, headers: { 'content-type': 'text/html' } })), error => error instanceof PreviewLoadError && error.code === code);
    });
}

for (const type of ['text/html', 'application/json', 'application/octet-stream', '']) {
    test(`HTTP 200 with ${type || 'missing MIME'} is rejected before embedding`, async () => {
        const response = new Response('<html>Login or Inertia page</html>', { headers: { 'content-type': type } });
        await assert.rejects(load(response), error => error.code === 'response');
    });
}

test('a claimed PDF containing HTML is rejected by its bytes', async () => {
    await assert.rejects(load(new Response('<html>Access Restricted</html>', { headers: { 'content-type': 'application/pdf' } })), error => error.code === 'response');
});

test('PDF, JPEG and PNG signature bytes are accepted with normalized MIME', async () => {
    for (const [type, bytes] of [['application/pdf; charset=binary', new TextEncoder().encode('%PDF-1.7')], ['image/jpeg', new Uint8Array([255, 216, 255, 224])], ['image/png', new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])]]) {
        const result = await load(new Response(bytes, { headers: { 'content-type': type } }));
        assert.equal(result.type, type.split(';')[0]);
        assert.equal(result.blob.type, type.split(';')[0]);
    }
});

test('empty file and image signature mismatches remain errors', async () => {
    for (const [type, body] of [['application/pdf', ''], ['image/png', '%PDF-1.7'], ['image/jpeg', '<html>']]) {
        await assert.rejects(load(new Response(body, { headers: { 'content-type': type } })), error => error.code === 'response');
    }
});

test('unsupported descriptor MIME remains a download-only type', () => {
    assert.equal(inlinePreviewType(' APPLICATION/PDF; charset=binary '), 'application/pdf');
    assert.equal(inlinePreviewType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'), null);
    assert.equal(inlinePreviewType(null), null);
});

test('cross-origin, script, and credential-bearing URLs never reach fetch', async () => {
    let calls = 0;
    for (const url of ['https://other.test/file.pdf', 'javascript:alert(1)', 'https://user:password@cds.test/file.pdf']) {
        await assert.rejects(loadProtectedPreview(url, { baseUrl, fetchImpl: () => { calls++; } }), error => error.code === 'response');
    }
    assert.equal(calls, 0);
});

test('redirected response is rejected even if it claims a PDF', async () => {
    const response = pdf();
    Object.defineProperty(response, 'redirected', { value: true });
    await assert.rejects(load(response), error => error.code === 'response');
});

test('network or refused auth redirect yields a generic error without exposing server body', async () => {
    await assert.rejects(load(null, { fetchImpl: async () => { throw new TypeError('redirect failed'); } }), error => error.code === 'network');
});

test('successful task creates exactly one blob URL and disposal revokes it once', async () => {
    const states = [], created = [], revoked = [];
    const task = startProtectedPreview(previewUrl, { baseUrl, fetchImpl: async () => pdf(), onState: state => states.push(state), urlApi: {
        createObjectURL: blob => { created.push(blob); return 'blob:fixture'; }, revokeObjectURL: url => revoked.push(url),
    } });
    await task.done;
    assert.deepEqual(states.map(state => state.status), ['loading', 'ready']);
    assert.equal(states[1].url, 'blob:fixture');
    assert.match(await states[1].blob.text(), /^%PDF-1\.7/);
    assert.equal(created.length, 1);
    task.dispose(); task.dispose();
    assert.deepEqual(revoked, ['blob:fixture']);
});

test('close or record switch aborts and suppresses a late completion even if fetch ignores cancellation', async () => {
    let resolve, signal;
    const states = [], created = [];
    const task = startProtectedPreview(previewUrl, { baseUrl, fetchImpl: (url, options) => {
        signal = options.signal;
        return new Promise(done => { resolve = done; });
    }, onState: state => states.push(state), urlApi: { createObjectURL: blob => created.push(blob), revokeObjectURL: () => {} } });
    task.dispose();
    assert.equal(signal.aborted, true);
    resolve(pdf());
    await task.done;
    assert.deepEqual(states.map(state => state.status), ['loading']);
    assert.equal(created.length, 0);
});

test('denied task never creates an object URL and can be retried after session/props change', async () => {
    const states = [];
    let count = 0;
    const urlApi = { createObjectURL: () => { count++; return 'blob:retry'; }, revokeObjectURL: () => {} };
    const failed = startProtectedPreview(previewUrl, { baseUrl, fetchImpl: async () => new Response('', { status: 403 }), onState: state => states.push(state), urlApi });
    await failed.done;
    assert.equal(states.at(-1).error.code, 'forbidden');
    assert.equal(count, 0);
    failed.dispose();
    const retried = startProtectedPreview(previewUrl, { baseUrl, fetchImpl: async () => pdf(), onState: state => states.push(state), urlApi });
    await retried.done;
    assert.equal(states.at(-1).status, 'ready');
    assert.equal(count, 1);
    retried.dispose();
});
