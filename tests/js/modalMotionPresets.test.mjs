import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { MODAL_MOTION_PRESETS } from '../../resources/js/Components/ModalMotion/presets.mjs';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('dialog purposes share the centered alert and Side Fall content presets', () => {
    assert.equal(MODAL_MOTION_PRESETS.alert, 'super-scaled');
    assert.equal(MODAL_MOTION_PRESETS.blocking, 'super-scaled');
    assert.equal(MODAL_MOTION_PRESETS.details, 'side-fall');
    assert.equal(MODAL_MOTION_PRESETS.form, 'side-fall');
    assert.equal(MODAL_MOTION_PRESETS.reader, 'side-fall');

    assert.match(read('../../resources/js/Components/ConfirmDialog.jsx'), /motion=\{MODAL_MOTION_PRESETS\.alert\}/);
    assert.match(read('../../resources/js/Components/SuccessDialog.jsx'), /motion=\{MODAL_MOTION_PRESETS\.alert\}/);
    assert.match(read('../../resources/js/Components/Crud/CrudDetailsModal.jsx'), /motion=\{MODAL_MOTION_PRESETS\.blocking\}/);
    assert.match(read('../../resources/js/Components/Crud/CrudFormModal.jsx'), /motion=\{MODAL_MOTION_PRESETS\.blocking\}/);
    const finalArchiveReader = read('../../resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx');
    assert.match(finalArchiveReader, /motion=\{MODAL_MOTION_PRESETS\.blocking\}/);
    assert.match(finalArchiveReader, /initialFocusSelector="\[data-cds-busy-status\]"/);
});

test('Super Scaled stays centered, short, mobile-safe, and reduced-motion compatible', () => {
    const styles = read('../../resources/css/app.css');
    assert.match(styles, /--cds-modal-super-scaled-enter-scale:\s*1\.08/);
    assert.match(styles, /--cds-modal-super-scaled-exit-scale:\s*1\.04/);
    assert.match(styles, /--cds-modal-super-scaled-duration:\s*260ms/);
    assert.match(styles, /--cds-modal-super-scaled-enter-scale:\s*1\.04/);
    assert.match(styles, /data-cds-modal-motion="super-scaled"\]\[data-motion-state="entering"\]\s*\{\s*opacity:\s*0;\s*transform:\s*scale\(/);
    assert.match(styles, /data-cds-modal-motion="super-scaled"\]\[data-motion-state="closing"\]\s*\{\s*opacity:\s*0;\s*transform:\s*scale\(/);
    assert.match(styles, /prefers-reduced-motion:\s*reduce/);
});
