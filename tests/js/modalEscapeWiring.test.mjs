import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const callsiteFiles = [
    'resources/js/Components/Modal.jsx',
    'resources/js/Pages/AWS/Aws.jsx',
    'resources/js/Pages/Bms/Index.jsx',
    'resources/js/Pages/Calendar/Index.jsx',
    'resources/js/Pages/Imea/FacilitiesReport.jsx',
    'resources/js/Pages/Imea/Index.jsx',
    'resources/js/Pages/Imea/Report.jsx',
    'resources/js/Pages/ProtectedAreas/Form.jsx',
    'resources/js/Pages/ProtectedAreas/Index.jsx',
    'resources/js/Pages/Admin/Settings/ModuleManagement.jsx',
];

function modalMotionCalls(source) {
    const calls = [];
    const marker = 'useModalMotion(';
    let from = 0;

    while (true) {
        const start = source.indexOf(marker, from);
        if (start < 0) return calls;
        const open = start + marker.length - 1;
        let depth = 0;
        let quote = null;
        let escaped = false;

        for (let cursor = open; cursor < source.length; cursor += 1) {
            const char = source[cursor];
            const next = source[cursor + 1];

            if (quote) {
                if (escaped) escaped = false;
                else if (char === '\\') escaped = true;
                else if (char === quote) quote = null;
                continue;
            }
            if (char === '/' && next === '/') {
                const newline = source.indexOf('\n', cursor + 2);
                cursor = newline < 0 ? source.length : newline;
                continue;
            }
            if (char === '/' && next === '*') {
                const end = source.indexOf('*/', cursor + 2);
                cursor = end < 0 ? source.length : end + 1;
                continue;
            }
            if (char === "'" || char === '"' || char === '`') {
                quote = char;
                continue;
            }
            if (char === '(') depth += 1;
            if (char === ')') depth -= 1;
            if (depth === 0) {
                calls.push(source.slice(start, cursor + 1));
                from = cursor + 1;
                break;
            }
        }
    }
}

test('every shared modal-motion registration in modal callsite pages supplies its Escape close callback', () => {
    let count = 0;
    for (const file of callsiteFiles) {
        const source = readFileSync(resolve(file), 'utf8');
        const calls = modalMotionCalls(source);
        assert.ok(calls.length > 0, `${file} should remain covered by the modal Escape contract`);
        for (const [index, call] of calls.entries()) {
            count += 1;
            assert.match(call, /\bonClose\b/, `${file} useModalMotion call ${index + 1} needs to register Escape dismissal`);
        }
    }
    assert.equal(count, 18, 'the reviewed modal callsite inventory should remain complete');
});
