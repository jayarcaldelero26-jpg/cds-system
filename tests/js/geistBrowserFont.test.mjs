import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const tailwind = readFileSync(new URL('../../tailwind.config.js', import.meta.url), 'utf8');
const font = readFileSync(new URL('../../resources/fonts/Geist-Variable.woff2', import.meta.url));

test('browser UI uses the locally bundled licensed Geist Sans variable font', () => {
    assert.equal(font.toString('ascii', 0, 4), 'wOF2');
    assert.ok(statSync(new URL('../../resources/fonts/Geist-Variable.woff2', import.meta.url)).size > 60_000);
    assert.match(css, /@font-face[\s\S]*?Geist Sans Variable[\s\S]*?Geist-Variable\.woff2[\s\S]*?font-weight:\s*100\s+900/);
    assert.match(tailwind, /sans:\s*\['Geist Sans Variable'/);
    assert.doesNotMatch(css, /fonts\.googleapis\.com\/css2\?family=(?:Inter|Manrope)/);
    assert.match(readFileSync(new URL('../../resources/fonts/OFL.txt', import.meta.url), 'utf8'), /SIL OPEN FONT LICENSE Version 1\.1/);
});
