import test from 'node:test';
import assert from 'node:assert/strict';
import { fixedBottomOverlayInset } from '../../resources/js/Utils/fixedBottomOverlayInset.mjs';

test('reserves the measured fixed-toolbar height only where it crosses the reader', () => {
    const desktopPanel = { left: 480, right: 1440 };
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 1920, top: 912, bottom: 945 },
        panelRect: desktopPanel,
        viewportHeight: 945,
    }), 33);
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 1366, top: 735, bottom: 768 },
        panelRect: { left: 203, right: 1163 },
        viewportHeight: 768,
    }), 33);
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 390, top: 747, bottom: 844 },
        panelRect: { left: 8, right: 382 },
        viewportHeight: 844,
    }), 97);
});

test('ignores hidden bars and collapsed desktop controls outside the reader width', () => {
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 33, top: 912, bottom: 945 },
        panelRect: { left: 480, right: 1440 },
        viewportHeight: 945,
    }), 0);
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 1920, top: 912, bottom: 945 },
        panelRect: { left: 480, right: 1440 },
        viewportHeight: 945,
        visible: false,
    }), 0);
    assert.equal(fixedBottomOverlayInset({
        toolbarRect: { left: 0, right: 1920, top: 700, bottom: 900 },
        panelRect: { left: 480, right: 1440 },
        viewportHeight: 945,
    }), 0);
});
