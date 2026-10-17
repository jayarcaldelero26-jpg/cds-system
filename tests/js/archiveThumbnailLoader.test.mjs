import assert from 'node:assert/strict';
import test from 'node:test';
import {
    archiveThumbnailIdentity,
    archiveThumbnailScopeKey,
    createArchiveThumbnailCache,
    createArchiveThumbnailLoader,
} from '../../resources/js/Utils/archiveThumbnailLoader.mjs';
import { loadProtectedPreview } from '../../resources/js/Utils/protectedDocumentPreview.mjs';

const sourcePdf = new TextEncoder().encode('%PDF-1.7\n1 0 obj<</Type/Catalog>>endobj\n%%EOF');
const thumbBytes = new Uint8Array([0xff, 0xd8, 0xff, 0xd9]);
const auth = (id, userCategory = 'penro_cds_focal') => ({
    user: { id, office_designated: 'PENRO Davao Oriental', protected_area_id: null, user_category: userCategory, roles: ['PENRO CDS Focal'], is_active: true, is_approved: true },
    canViewSubmissionTracking: true,
    canViewFinalArchives: true,
});

function finalRow(source = 'bms', sourceId = 8, fileId = 41) {
    const file = { id: fileId, name: 'signed-return.pdf', mime_type: 'application/pdf', size: sourcePdf.length, status: 'ARCHIVED', preview_url: `/submission-tracking/final-archives/files/${fileId}?preview=1`, download_url: `/submission-tracking/final-archives/files/${fileId}?download=1` };
    return { source, source_id: sourceId, manifest: { id: 81, revision: 2, status: 'ARCHIVED', finalized_at: '2026-10-17T02:00:00Z', files: [file] } };
}

test('thumbnail identity is exact-source, revision, part, and account scoped', () => {
    const scopeA = archiveThumbnailScopeKey(auth(7));
    const scopeB = archiveThumbnailScopeKey(auth(8));
    const base = finalRow();
    const file = base.manifest.files[0];
    const identity = archiveThumbnailIdentity(base, file, scopeA);

    assert.ok(identity);
    assert.notEqual(identity, archiveThumbnailIdentity(base, file, scopeB));
    assert.notEqual(identity, archiveThumbnailIdentity(finalRow('conservation', 8, 41), finalRow('conservation', 8, 41).manifest.files[0], scopeA));
    assert.notEqual(identity, archiveThumbnailIdentity(finalRow('bms', 9, 41), finalRow('bms', 9, 41).manifest.files[0], scopeA));
    const changedRevision = finalRow();
    changedRevision.manifest.revision = 3;
    assert.notEqual(identity, archiveThumbnailIdentity(changedRevision, changedRevision.manifest.files[0], scopeA));
    const changedPart = finalRow('bms', 8, 42);
    assert.notEqual(identity, archiveThumbnailIdentity(changedPart, changedPart.manifest.files[0], scopeA));
    assert.equal(archiveThumbnailScopeKey({ user: { id: 7 }, canViewFinalArchives: false }), '');
    assert.notEqual(scopeA, archiveThumbnailScopeKey({ ...auth(7), canViewFinalArchives: false }));
});

test('incomplete, unverified, unsupported, or missing-scope descriptors cannot request thumbnails', () => {
    const scope = archiveThumbnailScopeKey(auth(7));
    const row = finalRow();
    const file = row.manifest.files[0];
    assert.ok(archiveThumbnailIdentity(row, file, scope));
    assert.equal(archiveThumbnailIdentity(row, file, ''), null);
    assert.equal(archiveThumbnailIdentity({ ...row, manifest: { ...row.manifest, status: 'FAILED' } }, file, scope), null);
    assert.equal(archiveThumbnailIdentity({ ...row, manifest: { ...row.manifest, files: [{ ...file, preview_url: null }] } }, file, scope), null);
    assert.equal(archiveThumbnailIdentity(row, { ...file, mime_type: 'application/vnd.ms-excel' }, scope), null);

    const dispatch = { source: 'conservation', source_id: 39, earlier_dispatch_copy: true, manifest: { id: 'dispatch-14', status: 'EARLIER_DISPATCH_COPY', files: [{ ...file, id: 14, preview_url: '/dispatch/14?preview=1', download_url: null }] } };
    assert.equal(archiveThumbnailIdentity(dispatch, dispatch.manifest.files[0], scope), null, 'earlier copy needs its matching protected download descriptor too');
    dispatch.manifest.files[0].download_url = '/dispatch/14?download=1';
    assert.ok(archiveThumbnailIdentity(dispatch, dispatch.manifest.files[0], scope), 'authorized Earlier Dispatch copy uses its own protected route');
});

test('visible thumbnail loads one full protected document, caches only the small rendered image, and clears on account change', async () => {
    const requests = [];
    const fetchImpl = async (url, options) => {
        requests.push({ url, method: 'GET', credentials: options.credentials, bytes: sourcePdf.length });
        return new Response(new Blob([sourcePdf], { type: 'application/pdf' }), {
            status: 200,
            headers: { 'Content-Type': 'application/pdf' },
        });
    };
    let renders = 0;
    const cache = createArchiveThumbnailCache({ maxEntries: 3, maxBytes: 1024 });
    const loader = createArchiveThumbnailLoader({
        cache,
        fetchPreview: (url, { signal }) => loadProtectedPreview(url, { baseUrl: 'https://cds.test/submission-tracking/archives', fetchImpl, signal }),
        renderPdf: async blob => {
            renders += 1;
            assert.equal(blob.size, sourcePdf.length);
            return new Blob([thumbBytes], { type: 'image/jpeg' });
        },
        concurrency: 2,
    });
    const rowA = finalRow();
    const fileA = rowA.manifest.files[0];
    const scopeA = archiveThumbnailScopeKey(auth(7));
    const keyA = archiveThumbnailIdentity(rowA, fileA, scopeA);
    const first = loader.request({ scopeKey: scopeA, key: keyA, url: fileA.preview_url, type: fileA.mime_type });
    const duplicate = loader.request({ scopeKey: scopeA, key: keyA, url: fileA.preview_url, type: fileA.mime_type });
    const [thumbA, thumbB] = await Promise.all([first.promise, duplicate.promise]);
    first.release();
    duplicate.release();

    assert.equal(thumbA.size, thumbBytes.length);
    assert.equal(thumbB.size, thumbBytes.length);
    assert.equal(requests.length, 1, 'identical visible cards share in-flight protected byte work');
    assert.equal(requests[0].method, 'GET');
    assert.equal(requests[0].credentials, 'same-origin');
    assert.equal(requests[0].bytes, sourcePdf.length, 'the fetch transfers the complete PDF even though the rendered canvas is small');
    assert.equal(renders, 1);
    assert.deepEqual(loader.stats(), { entries: 1, bytes: thumbBytes.length, queued: 0, active: 0, inFlight: 0 });

    const cached = loader.request({ scopeKey: scopeA, key: keyA, url: fileA.preview_url, type: fileA.mime_type });
    assert.equal((await cached.promise).size, thumbBytes.length);
    cached.release();
    assert.equal(requests.length, 1, 'a reread from the same exact revision uses the bounded thumbnail cache');

    const scopeB = archiveThumbnailScopeKey(auth(8));
    const rowB = finalRow();
    const fileB = rowB.manifest.files[0];
    const keyB = archiveThumbnailIdentity(rowB, fileB, scopeB);
    const changedAccount = loader.request({ scopeKey: scopeB, key: keyB, url: fileB.preview_url, type: fileB.mime_type });
    await changedAccount.promise;
    changedAccount.release();
    assert.equal(requests.length, 2);
    assert.equal(loader.stats().entries, 1, 'account/scope change clears the prior account cache');
});

test('thumbnail work is bounded and a queued card can be cancelled before its GET begins', async () => {
    let releaseFetch;
    const gate = new Promise(resolve => { releaseFetch = resolve; });
    let started = 0;
    let active = 0;
    let maximumActive = 0;
    const loader = createArchiveThumbnailLoader({
        concurrency: 2,
        fetchPreview: async (_url, { signal }) => {
            started += 1;
            active += 1;
            maximumActive = Math.max(maximumActive, active);
            await Promise.race([gate, new Promise((_, reject) => signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })), { once: true }))]);
            active -= 1;
            return { blob: new Blob([sourcePdf], { type: 'application/pdf' }), type: 'application/pdf' };
        },
        renderPdf: async () => new Blob([thumbBytes], { type: 'image/jpeg' }),
    });
    const scope = archiveThumbnailScopeKey(auth(7));
    const rowOne = finalRow('bms', 8, 51);
    const rowTwo = finalRow('bms', 9, 52);
    const rowThree = finalRow('bms', 10, 53);
    const leaseOne = loader.request({ scopeKey: scope, key: archiveThumbnailIdentity(rowOne, rowOne.manifest.files[0], scope), url: '/one', type: 'application/pdf' });
    const leaseTwo = loader.request({ scopeKey: scope, key: archiveThumbnailIdentity(rowTwo, rowTwo.manifest.files[0], scope), url: '/two', type: 'application/pdf' });
    const leaseThree = loader.request({ scopeKey: scope, key: archiveThumbnailIdentity(rowThree, rowThree.manifest.files[0], scope), url: '/three', type: 'application/pdf' });
    const thirdResult = leaseThree.promise.catch(error => error);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(loader.stats().active, 2);
    leaseThree.release();
    releaseFetch();
    await Promise.all([leaseOne.promise, leaseTwo.promise, thirdResult]);
    leaseOne.release();
    leaseTwo.release();
    assert.equal(started, 2, 'the cancelled offscreen queue item never starts a protected GET');
    assert.equal(maximumActive, 2);
    assert.equal(loader.stats().active, 0);
});
