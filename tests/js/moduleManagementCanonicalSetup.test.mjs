import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/ModuleManagement.jsx', import.meta.url), 'utf8');

test('module management exposes the guarded missing canonical Conservation setup action', () => {
    assert.match(source, /needsCanonicalConservationSetup/);
    assert.match(source, /module-definitions\.ensure-canonical-conservation/);
    assert.match(source, /Add missing canonical Conservation definitions/);
    assert.match(source, /Existing settings and inactive status are preserved/);
});

test('module management exposes the guarded missing canonical ENGP setup action', () => {
    assert.match(source, /needsCanonicalEngpSetup/);
    assert.match(source, /module-definitions\.ensure-canonical-engp/);
    assert.match(source, /Add missing canonical ENGP definitions/);
    assert.match(source, /registered workflow metadata/);
    assert.match(source, /Existing settings and inactive status are preserved/);
});
