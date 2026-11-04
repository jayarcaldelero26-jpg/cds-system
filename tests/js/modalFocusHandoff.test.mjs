import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseModalFocusTarget, restoreModalFocusTarget } from '../../resources/js/Components/ModalMotion/focusReturn.mjs';

test('focus handoff blurs the active form descendant before its modal can be hidden', () => {
    const documentObject = { activeElement: null };
    const body = {};
    const submit = {
        blurCount: 0,
        blur() {
            this.blurCount++;
            documentObject.activeElement = body;
        },
    };
    const form = { contains: element => element === submit };
    documentObject.activeElement = submit;

    const returnTarget = releaseModalFocusTarget(form, documentObject);

    assert.equal(returnTarget, submit);
    assert.equal(submit.blurCount, 1);
    assert.equal(documentObject.activeElement, body);
    assert.equal(form.contains(documentObject.activeElement), false);
});

test('focus handoff leaves an outside target unchanged and can restore only after visibility returns', () => {
    const documentObject = { activeElement: null, contains: target => target === form || target === submit };
    const submit = { focused: 0, blur() { assert.fail('outside focus must not be blurred'); }, focus() { this.focused++; } };
    const outside = { blurCount: 0, blur() { this.blurCount++; } };
    const form = { contains: target => target === submit, visible: true };
    documentObject.activeElement = outside;

    assert.equal(releaseModalFocusTarget(form, documentObject), null);
    assert.equal(outside.blurCount, 0);

    let restore;
    form.visible = false;
    assert.equal(restoreModalFocusTarget(submit, documentObject, callback => { restore = callback; }), true);
    assert.equal(submit.focused, 0);
    form.visible = true;
    restore();
    assert.equal(submit.focused, 1);
});
