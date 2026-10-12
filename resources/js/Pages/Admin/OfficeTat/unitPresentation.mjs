const units = new Set(['conservation', 'development']);

export function officeTatDisplayUnit(recordedAssessment, selectedUnit) {
    if (units.has(recordedAssessment?.unit)) return recordedAssessment.unit;
    if (units.has(selectedUnit)) return selectedUnit;
    return 'conservation';
}
