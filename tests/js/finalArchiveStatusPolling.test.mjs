import assert from 'node:assert/strict';
import test from 'node:test';
import {
    FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS,
    hasFinalArchiveStatusPollAttemptsRemaining,
    MAX_FINAL_ARCHIVE_STATUS_POLL_ATTEMPTS,
    shouldPollFinalArchiveStatus,
} from '../../resources/js/Utils/finalArchiveStatusPolling.mjs';

test('final archive status polling is read-only, active-claim-only, and bounded', () => {
    assert.equal(FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS, 5000);
    assert.equal(MAX_FINAL_ARCHIVE_STATUS_POLL_ATTEMPTS, 36);
    assert.equal(shouldPollFinalArchiveStatus({ status: 'PROCESSING', processing_claim_active: true }), true);
    assert.equal(shouldPollFinalArchiveStatus({ status: 'PROCESSING', processing_claim_active: false }), false);
    assert.equal(shouldPollFinalArchiveStatus({ status: 'FAILED', processing_claim_active: true }), false);
    assert.equal(hasFinalArchiveStatusPollAttemptsRemaining(0), true);
    assert.equal(hasFinalArchiveStatusPollAttemptsRemaining(35), true);
    assert.equal(hasFinalArchiveStatusPollAttemptsRemaining(36), false);
    assert.equal(hasFinalArchiveStatusPollAttemptsRemaining(-1), false);
});
