import assert from 'node:assert/strict';
import test from 'node:test';
import { createUuid } from '../../resources/js/Utils/createUuid.mjs';

test('uses the native UUID generator when the browser exposes it', () => {
    let fallbackCalled = false;

    const uuid = createUuid({
        randomUUID: () => 'native-uuid',
        getRandomValues: () => { fallbackCalled = true; },
    });

    assert.equal(uuid, 'native-uuid');
    assert.equal(fallbackCalled, false);
});

test('creates a valid version 4 UUID from getRandomValues when randomUUID is unavailable', () => {
    const uuid = createUuid({ getRandomValues: bytes => bytes.fill(0) });

    assert.equal(uuid, '00000000-0000-4000-8000-000000000000');
    assert.match(uuid, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});

test('fails clearly if Web Crypto cannot generate a UUID', () => {
    assert.throws(() => createUuid({}), /Web Crypto is required/);
});
