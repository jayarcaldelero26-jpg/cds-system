import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { officeDisplayLabel, pamoEligibilityGuidance } from '../../resources/js/Components/Form/officePresentation.mjs';

test('office labels use canonical names while preserving distinct stable IDs', () => {
    const offices = [
        { id: 11, name: 'CENRO Baganga', code: 'cenro_baganga', office_type: 'cenro', label: 'CENRO Baganga · cenro_baganga · CENRO' },
        { id: 12, name: 'PENRO Davao Oriental', code: 'penro_davao_oriental', office_type: 'penro' },
        { id: 13, name: 'CENRO Baganga', code: 'other_baganga', office_type: 'cenro' },
    ];
    const options = offices.map(office => ({ ...office, label: officeDisplayLabel(office) }));
    assert.deepEqual(options.map(option => option.label), ['CENRO Baganga', 'PENRO Davao Oriental', 'CENRO Baganga']);
    assert.deepEqual(options.map(option => option.id), [11, 12, 13]);
    assert.equal(officeDisplayLabel({ name: 'PAMO MHRWS', code: 'pamo_mhrws' }), 'PAMO MHRWS');
});

test('eligibility guidance keeps the specific server reason', () => {
    assert.equal(pamoEligibilityGuidance('Protected Area status is Proposed; only Active Protected Areas can register PAMO accounts.'), 'Set the status to Active to allow PAMO registration.');
    assert.equal(pamoEligibilityGuidance('No stable PAMO office identity is mapped.'), 'Assign a PAMO office to allow registration.');
    assert.equal(pamoEligibilityGuidance('No active supervising office is mapped.'), 'Select an active supervising office to allow registration.');
    assert.match(pamoEligibilityGuidance('The mapped PAMO office is inactive.'), /inactive/);
});

test('shared selector keeps ID selection, keyboard search and Escape behavior', async () => {
    const selector = await readFile(new URL('../../resources/js/Components/Form/ScopedOptionSelect.jsx', import.meta.url), 'utf8');
    const form = await readFile(new URL('../../resources/js/Pages/ProtectedAreas/Form.jsx', import.meta.url), 'utf8');
    assert.match(selector, /onChange\?\.\(String\(option\.id\)\)/);
    assert.match(selector, /selected\?\.label \|\| selected\?\.name/);
    assert.match(selector, /option\.label \|\| option\.name/);
    assert.match(selector, /event\.key === 'Escape'/);
    assert.match(selector, /setQuery\(event\.target\.value\)/);
    assert.match(form, /pamo_office_id: id, pamo: office\?\.name \|\| ''/);
    assert.match(form, /form\.setData\('supervising_office_id', id\)/);
    assert.doesNotMatch(form, /office\.code.*office\.office_type/);
});

test('registration and user management show canonical CENRO names', async () => {
    for (const path of ['../../resources/js/Pages/Auth/Register.jsx', '../../resources/js/Pages/Admin/Users/Form.jsx']) {
        const source = await readFile(new URL(path, import.meta.url), 'utf8');
        assert.match(source, /<option key=\{office\.id\} value=\{office\.name\}>\{officeDisplayLabel\(office\)\}<\/option>/);
    }
});
