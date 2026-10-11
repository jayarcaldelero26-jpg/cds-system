const PENDING_ROUTE_STATUSES = new Set([
    'pending receipt by penro',
    'pending regional endorsement',
]);

export function presentOperationalStatus(value) {
    const context = String(value ?? '').trim();
    const normalized = context.toLocaleLowerCase('en');
    const pendingSubmission = context.match(/^Pending Submission by (.+)$/i);
    const compact = Boolean(pendingSubmission)
        || PENDING_ROUTE_STATUSES.has(normalized)
        || /^awaiting forward to .+$/i.test(context)
        || /^awaiting receipt by .+$/i.test(context);
    const displayContext = pendingSubmission
        ? `Submission by ${pendingSubmission[1].trim()}`
        : context;

    return compact
        ? { badge: 'Pending', context: displayContext, compact: true, state: 'pending' }
        : { badge: context, context: null, compact: false, state: null };
}
