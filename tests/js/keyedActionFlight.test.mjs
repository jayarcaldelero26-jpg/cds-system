import test from 'node:test';
import assert from 'node:assert/strict';
import { createKeyedActionFlight } from '../../resources/js/Utils/keyedActionFlight.mjs';

test('rapid duplicate clicks are blocked per report and action, then released after failure', () => {
    const flight = createKeyedActionFlight();
    const submitA = 'bms:41:mov-submit';
    const submitB = 'bms:42:mov-submit';
    const receiveA = 'bms:41:receive-correction';

    assert.equal(flight.begin(submitA), true);
    assert.equal(flight.begin(submitA), false);
    assert.equal(flight.begin(submitB), true);
    assert.equal(flight.begin(receiveA), true);
    assert.deepEqual(new Set(flight.values()), new Set([submitA, submitB, receiveA]));

    // Network/server failure reaches the same completion path as success.
    flight.finish(submitA);
    assert.equal(flight.has(submitA), false);
    assert.equal(flight.begin(submitA), true);
    assert.equal(flight.has(submitB), true);
});
