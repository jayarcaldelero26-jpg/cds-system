/**
 * Keep Incoming action tabs as an optional narrowing filter. A missing or
 * stale selection must not silently hide actionable records from another
 * action category.
 */
export function availableIncomingActionTabs(rows, categoryOrder) {
    return categoryOrder.filter((category) =>
        rows.some((row) => row.incoming_action_category === category),
    );
}

export function reconcileIncomingActionTab(selected, available) {
    return selected && available.includes(selected) ? selected : null;
}

export function filterIncomingRowsByAction(rows, selected) {
    return selected
        ? rows.filter((row) => row.incoming_action_category === selected)
        : rows;
}
