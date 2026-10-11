export function recordedResultPresentation(row, asOfDate) {
    const activityCount = Number(row?.activity_count ?? 0);
    const receivedCount = Number(row?.received_activity_count ?? 0);
    const notYetDueCount = Number(row?.not_yet_due_count ?? 0);
    const dueCount = Number(row?.due_count ?? 0);
    const incompleteDateCount = Number(row?.incomplete_date_count ?? 0);
    const incompleteEvidenceCount = Number(row?.incomplete_evidence_count ?? 0);

    const allRecordsReceivedAndFuture = row?.score_status === 'No due reports'
        && activityCount > 0
        && receivedCount === activityCount
        && notYetDueCount === activityCount
        && dueCount === 0
        && incompleteDateCount === 0
        && incompleteEvidenceCount === 0;

    if (!allRecordsReceivedAndFuture) {
        return { label: row?.score_status || 'Not assessed', nextDeadline: null };
    }

    const nextDeadline = (row?.obligation_details || [])
        .map(item => item?.deadline)
        .filter(deadline => typeof deadline === 'string' && deadline > asOfDate)
        .sort()[0] || null;

    return { label: 'Received — not yet due', nextDeadline };
}
