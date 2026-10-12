export const officeDisplayLabel = (office) => office?.name || '';

export const pamoEligibilityGuidance = (issue) => {
    if (!issue) return '';
    if (issue.startsWith('Protected Area status is ')) return 'Set the status to Active to allow PAMO registration.';
    if (issue === 'No stable PAMO office identity is mapped.') return 'Assign a PAMO office to allow registration.';
    if (issue === 'No active supervising office is mapped.') return 'Select an active supervising office to allow registration.';
    return issue;
};
