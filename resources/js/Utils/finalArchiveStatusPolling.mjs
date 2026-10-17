export const FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS = 5000;
export const MAX_FINAL_ARCHIVE_STATUS_POLL_ATTEMPTS = 36;

export function shouldPollFinalArchiveStatus(manifest) {
    return manifest?.status === 'PROCESSING' && manifest.processing_claim_active === true;
}

export function hasFinalArchiveStatusPollAttemptsRemaining(attempts) {
    return Number.isInteger(attempts)
        && attempts >= 0
        && attempts < MAX_FINAL_ARCHIVE_STATUS_POLL_ATTEMPTS;
}
