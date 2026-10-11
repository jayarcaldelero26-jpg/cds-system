import assert from 'node:assert/strict';
import test from 'node:test';
import { focusInitialModalTarget, resolveCrudFormInitialFocusSelector } from '../../resources/js/Components/ModalMotion/initialFocus.mjs';

function makeElement({ id, documentObject, visible = true, disabled = false, hidden = false, type = '', attributes = {}, onFocus = () => {} }) {
    return {
        id,
        disabled,
        hidden,
        type,
        focusOptions: null,
        getAttribute(name) { return attributes[name] ?? null; },
        hasAttribute(name) { return Object.hasOwn(attributes, name); },
        closest() { return null; },
        getClientRects() { return visible ? [{}] : []; },
        focus(options) {
            this.focusOptions = options;
            documentObject.activeElement = this;
            onFocus();
        },
    };
}

function makeAddReportDialog() {
    const documentObject = { activeElement: null };
    const effects = { closeTooltipFocuses: 0, officeListOpens: 0 };
    const fieldValues = { targetOffice: '', protectedArea: '', dateConducted: '', remarks: '' };
    const opener = makeElement({ id: 'add-report', documentObject });
    const close = makeElement({ id: 'close-modal', documentObject, onFocus: () => effects.closeTooltipFocuses++ });
    const targetOffice = makeElement({ id: 'target-office', documentObject, onFocus: () => effects.officeListOpens++ });
    const workflow = makeElement({ id: 'historical-workflow', documentObject });
    const panel = makeElement({ id: 'dialog-panel', documentObject });
    const children = [close, targetOffice, workflow];
    panel.contains = element => element === panel || children.includes(element);
    panel.querySelector = selector => ({
        ':scope': panel,
        '#close-modal': close,
        '#historical-workflow': workflow,
    })[selector] || null;
    panel.querySelectorAll = () => children;
    return { documentObject, effects, fieldValues, opener, close, targetOffice, workflow, panel };
}

test('Add Report initial focus stays in its dialog shell without opening the first combobox or Close modal tooltip', () => {
    const fixture = makeAddReportDialog();
    fixture.opener.focus();
    const valuesBeforeOpen = { ...fixture.fieldValues };
    const selector = resolveCrudFormInitialFocusSelector(null);

    const focused = focusInitialModalTarget(fixture.panel, selector);

    assert.equal(selector, ':scope');
    assert.equal(focused, fixture.panel);
    assert.equal(fixture.documentObject.activeElement, fixture.panel);
    assert.deepEqual(fixture.panel.focusOptions, { preventScroll: true });
    assert.deepEqual(fixture.effects, { closeTooltipFocuses: 0, officeListOpens: 0 });
    assert.deepEqual(fixture.fieldValues, valuesBeforeOpen);

    // The close control remains focusable by keyboard, so its deliberate focus trigger remains available.
    fixture.close.focus();
    assert.equal(fixture.documentObject.activeElement, fixture.close);
    assert.equal(fixture.effects.closeTooltipFocuses, 1);
});

test('explicit initial-focus selectors remain authoritative for shared form callers', () => {
    const fixture = makeAddReportDialog();
    const selector = resolveCrudFormInitialFocusSelector('#historical-workflow');

    const focused = focusInitialModalTarget(fixture.panel, selector);

    assert.equal(focused, fixture.workflow);
    assert.equal(fixture.documentObject.activeElement, fixture.workflow);
    assert.equal(fixture.effects.officeListOpens, 0);
});

test('an unavailable requested target falls back to the first visible enabled control', () => {
    const documentObject = { activeElement: null };
    const panel = makeElement({ id: 'dialog-panel', documentObject });
    const disabled = makeElement({ id: 'disabled', documentObject, disabled: true });
    const hidden = makeElement({ id: 'hidden', documentObject, visible: false });
    const enabled = makeElement({ id: 'enabled', documentObject });
    const children = [disabled, hidden, enabled];
    panel.contains = element => element === panel || children.includes(element);
    panel.querySelector = () => disabled;
    panel.querySelectorAll = () => children;

    const focused = focusInitialModalTarget(panel, '#requested');

    assert.equal(focused, enabled);
    assert.equal(documentObject.activeElement, enabled);
});
