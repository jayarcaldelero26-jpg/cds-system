export function standardActionLabel(action = '') {
    const value = String(action || '').trim();
    if (/receive\s+correction/i.test(value)) return 'Receive Correction';
    if (/receive|receipt/i.test(value)) return 'Receive';
    if (/return|correction/i.test(value)) return 'Return for Correction';
    if (/approv/i.test(value)) return 'Approve';
    if (/recommend/i.test(value)) return 'Recommend';
    if (/release|endorse/i.test(value)) return 'Release';
    if (/forward|assign|transmit/i.test(value)) return 'Forward';
    if (/review/i.test(value)) return 'Review';
    return value || 'Open';
}

export function compactStatusForAction(action = '') {
    const value = String(action || '').trim();
    if (!value) return null;
    if (/return|correction/i.test(value)) return 'For Correction';
    if (/resubmit/i.test(value)) return 'For Resubmission';
    if (/approv/i.test(value)) return 'For Approval';
    if (/recommend/i.test(value)) return 'For Recommendation';
    if (/release|endorse|transmit/i.test(value)) return 'For Release';
    if (/forward|assign/i.test(value)) return 'For Forwarding';
    if (/receive|receipt/i.test(value)) return 'For Receipt';
    if (/review/i.test(value)) return 'For Review';
    return null;
}
