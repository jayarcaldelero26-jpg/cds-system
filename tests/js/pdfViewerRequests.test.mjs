import assert from 'node:assert/strict';
import test from 'node:test';
import { createRenderRequestIdentity } from '../../resources/js/Utils/pdfViewerRequests.mjs';

test('superseded zoom/page renders cannot commit or clear the latest render state', () => {
    const requests = createRenderRequestIdentity();
    const pageOne = requests.begin();
    const zoomedPageOne = requests.begin();
    const pageSixAtHighZoom = requests.begin();

    assert.equal(requests.isCurrent(pageOne), false);
    assert.equal(requests.isCurrent(zoomedPageOne), false);
    assert.equal(requests.isCurrent(pageSixAtHighZoom), true);
    requests.invalidate(zoomedPageOne);
    assert.equal(requests.isCurrent(pageSixAtHighZoom), true);
    requests.invalidate(pageSixAtHighZoom);
    assert.equal(requests.isCurrent(pageSixAtHighZoom), false);
});

test('cleanup invalidates its render request and cannot invalidate a newer request', () => {
    const requests = createRenderRequestIdentity();
    const closing = requests.begin();
    requests.invalidate(closing);
    assert.equal(requests.isCurrent(closing), false);

    const reopened = requests.begin();
    requests.invalidate(closing);
    assert.equal(requests.isCurrent(reopened), true);
});
