import assert from 'node:assert/strict';
import test from 'node:test';
import { activeModalLayerCount, dispatchModalKeydown, registerModalLayer, topModalLayer } from '../../resources/js/Components/ModalMotion/modalLayer.mjs';
import { MODAL_MOTION_DURATION, useModalMotion } from '../../resources/js/Components/ModalMotion/useModalMotion.js';

function makeEvent(key, overrides = {}) {
    return {
        key,
        shiftKey: false,
        target: null,
        prevented: false,
        stopped: false,
        preventDefault() { this.prevented = true; },
        stopPropagation() { this.stopped = true; },
        stopImmediatePropagation() { this.stopped = true; },
        ...overrides,
    };
}

function makeLayer({ id, zIndex, activeElement = null, focusables = [], visible = true, onEscape = () => {}, documentObject = null }) {
    documentObject ||= { activeElement, body: { style: { overflow: 'scroll' } }, addEventListener() {}, removeEventListener() {} };
    const panel = {
        ownerDocument: documentObject,
        querySelectorAll: () => focusables,
        contains: element => focusables.includes(element),
        focus() { this.focused = true; },
    };
    const layer = { ownerDocument: documentObject, getClientRects: () => visible ? [{}] : [] };
    return { entry: { id, zIndex, panel, layer, closeOnEscape: true, onEscape }, documentObject, panel };
}

test('shared modal layer selects the visible topmost layer and consumes Escape once', () => {
    let parentCloses = 0;
    let childCloses = 0;
    const parent = makeLayer({ id: 'parent', zIndex: 50, onEscape: () => parentCloses++ });
    const child = makeLayer({ id: 'preview', zIndex: 70, onEscape: () => childCloses++ });
    const hidden = makeLayer({ id: 'hidden', zIndex: 100, visible: false, onEscape: () => assert.fail('hidden layer handled Escape') });
    assert.equal(topModalLayer([parent.entry, child.entry, hidden.entry]).id, 'preview');
    const event = makeEvent('Escape');
    assert.equal(dispatchModalKeydown(event, [parent.entry, child.entry, hidden.entry]), true);
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, true);
    assert.equal(childCloses, 1);
    assert.equal(parentCloses, 0);
});

test('top modal traps only the Tab boundary and gives open dropdowns first Escape/Tab handling', () => {
    let firstFocused = 0;
    const first = { getClientRects: () => [{}], getAttribute: () => null, focus: () => firstFocused++ };
    const last = { getClientRects: () => [{}], getAttribute: () => null, focus() {} };
    const active = { getClientRects: () => [{}], getAttribute: () => null };
    const modal = makeLayer({ id: 'form', zIndex: 50, activeElement: active, focusables: [first, last] });
    const tabEvent = makeEvent('Tab', { target: active });
    assert.equal(dispatchModalKeydown(tabEvent, [modal.entry]), true);
    assert.equal(tabEvent.prevented, true);
    assert.equal(firstFocused, 1);

    const expandedControl = { closest: selector => selector.includes('[role="listbox"]') ? {} : (selector.includes('aria-expanded') ? expandedControl : null) };
    const escapeEvent = makeEvent('Escape', { target: expandedControl });
    assert.equal(dispatchModalKeydown(escapeEvent, [modal.entry]), false);
    assert.equal(escapeEvent.prevented, false);
});

test('layer registration shares scroll locking until the final dialog exits', () => {
    const first = makeLayer({ id: 'first', zIndex: 50 });
    const second = makeLayer({ id: 'second', zIndex: 60, documentObject: first.documentObject });
    const releaseFirst = registerModalLayer(first.entry);
    const releaseSecond = registerModalLayer(second.entry);
    assert.equal(activeModalLayerCount(), 2);
    assert.equal(first.documentObject.body.style.overflow, 'hidden');
    releaseSecond();
    assert.equal(activeModalLayerCount(), 1);
    assert.equal(first.documentObject.body.style.overflow, 'hidden');
    releaseFirst();
    assert.equal(activeModalLayerCount(), 0);
    assert.equal(first.documentObject.body.style.overflow, 'scroll');
    assert.equal(typeof useModalMotion, 'function');
    assert.equal(MODAL_MOTION_DURATION, 240);
});
