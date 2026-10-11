export function parseAssessmentPeriodKey(key) {
    const value = String(key || '');
    const semester = value.match(/^(\d{4})-H([12])$/);
    if (semester) return { type: 'semestral', year: Number(semester[1]), semester: `H${semester[2]}` };

    const annual = value.match(/^(\d{4})$/);
    if (annual) return { type: 'annual', year: Number(annual[1]), semester: null };

    return null;
}

export function assessmentPeriodKey(selection) {
    const year = Number(selection?.year);
    if (!Number.isInteger(year) || year < 2000 || year > 2100) return '';
    if (selection?.type === 'annual') return String(year);
    if (selection?.type === 'semestral' && ['H1', 'H2'].includes(selection.semester)) {
        return `${year}-${selection.semester}`;
    }
    return '';
}

export function selectAssessmentType(current, type) {
    const year = Number(current?.year);
    if (type === 'annual') return { type, year, semester: null };
    if (type === 'semestral') return { type, year, semester: current?.type === 'semestral' ? current.semester : 'H1' };
    return current;
}
