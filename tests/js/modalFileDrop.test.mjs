import assert from 'node:assert/strict';
import test from 'node:test';
import { assignDroppedFiles, fileMatchesAccept, forwardFileInputChange, getDropTarget, validateDroppedFiles } from '../../resources/js/Components/Attachments/modalFileDrop.mjs';

const makeFile = (name, type = '', size = 10) => ({ name, type, size });
const field = (accept, { multiple = false, maxSizeBytes = 0 } = {}) => ({ accept, multiple, disabled: false, dataset: { maxSizeBytes: String(maxSizeBytes) } });

test('a file dropped on its own zone is checked against that field accept rule', () => {
    const pdfField = field('.pdf');
    assert.equal(fileMatchesAccept(makeFile('report.pdf'), pdfField.accept), true);
    assert.deepEqual(validateDroppedFiles([makeFile('report.pdf')], pdfField).files.map((file) => file.name), ['report.pdf']);
});

test('a drop elsewhere in a modal selects the active field, then its marked primary field', () => {
    const csv = field('.csv');
    const pdf = field('.pdf');
    assert.equal(getDropTarget([csv, pdf], { active: pdf, primary: csv }), pdf);
    assert.equal(getDropTarget([csv, pdf], { primary: csv }), csv);
    assert.equal(getDropTarget([csv, pdf]), null);
});

test('a modal drop rejects file types and sizes outside the target field rules', () => {
    const constrained = field('.pdf', { maxSizeBytes: 100 });
    assert.deepEqual(validateDroppedFiles([makeFile('table.csv')], constrained).files, []);
    assert.match(validateDroppedFiles([makeFile('large.pdf', '', 101)], constrained).error, /maximum file size/);
});

test('a modal with distinct document slots never selects an unrelated slot by position', () => {
    const official = field('.pdf');
    const support = field('.docx');
    assert.equal(getDropTarget([official, support]), null);
    assert.equal(getDropTarget([official, support], { primary: official }), official);
    assert.equal(getDropTarget([official, support], { direct: support, primary: official }), support);
});

test('file selection dispatches the change event used by the existing browse handlers', () => {
    const priorDataTransfer = globalThis.DataTransfer;
    const priorEvent = globalThis.Event;
    class Transfer {
        constructor() { this.items = { values: [], add(file) { this.values.push(file); } }; }
        get files() { return this.items.values; }
    }
    globalThis.DataTransfer = Transfer;
    globalThis.Event = class { constructor(type, options) { this.type = type; this.bubbles = options.bubbles; } };
    try {
        const file = makeFile('selected.pdf');
        const input = { files: [], events: [], dispatchEvent(event) { this.events.push(event); } };
        assignDroppedFiles(input, [file]);
        assert.deepEqual(input.files, [file]);
        assert.equal(input.events[0].type, 'change');
        assert.equal(input.events[0].bubbles, true);
    } finally {
        globalThis.DataTransfer = priorDataTransfer;
        globalThis.Event = priorEvent;
    }
});

test('browse selection still forwards its original change event to the field handler', () => {
    let received;
    const event = { target: { files: [makeFile('browsed.pdf')] } };
    forwardFileInputChange((value) => { received = value; }, event);
    assert.equal(received, event);
    assert.equal(received.target.files[0].name, 'browsed.pdf');
});
