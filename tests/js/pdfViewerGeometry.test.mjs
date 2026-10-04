import assert from 'node:assert/strict';
import test from 'node:test';
import { fitPageScale, nextPage, nextZoom, zoomedScrollOffset } from '../../resources/js/Utils/pdfViewerGeometry.mjs';

test('Fit Page contains a portrait or landscape page without changing its aspect ratio', () => {
    for (const [width, height, containerWidth, containerHeight] of [[612, 792, 800, 500], [792, 612, 500, 800]]) {
        const scale = fitPageScale(width, height, containerWidth, containerHeight);
        assert.ok(width * scale <= containerWidth);
        assert.ok(height * scale <= containerHeight);
        assert.equal((width * scale) / (height * scale), width / height);
    }
});

test('Fit Page falls back safely for a not-yet-sized container', () => {
    assert.equal(fitPageScale(612, 792, 0, 0), 1);
    assert.equal(fitPageScale(Number.NaN, 792, 400, 600), 1);
});

test('multipage controls stay within document boundaries', () => {
    assert.equal(nextPage(1, 5, -1), 1);
    assert.equal(nextPage(4, 5, 1), 5);
    assert.equal(nextPage(5, 5, 1), 5);
});

test('zoom controls grow and shrink from Fit Page with bounded scale', () => {
    assert.ok(nextZoom(1, 1) > 1);
    assert.ok(nextZoom(1, -1) < 1);
    assert.equal(nextZoom(4, 1), 4);
    assert.equal(nextZoom(0.25, -1), 0.25);
});

test('wheel zoom preserves the document point beneath the pointer', () => {
    const scrollOffset = 240;
    const pointerOffset = 120;
    const nextOffset = zoomedScrollOffset(scrollOffset, pointerOffset, 1, 1.5);
    assert.equal((nextOffset + pointerOffset) / 1.5, (scrollOffset + pointerOffset) / 1);
    assert.equal(zoomedScrollOffset(0, 120, 1, 0.5), 0);
    assert.equal(zoomedScrollOffset(40, 10, 0, 2), 40);
});
