const PARENT_DATE_FIELDS = [
    "date_report_released_cenro",
    "date_received_penro",
    "date_endorsed_regional",
];

export function routingCorrectionDates(row) {
    const fields = row?.source === "engp"
        ? ["date_received_penro"]
        : PARENT_DATE_FIELDS;

    return Object.fromEntries(fields
        .filter((field) => Object.prototype.hasOwnProperty.call(row || {}, field))
        .map((field) => [field, row[field] || ""]));
}

export function routingCorrectionPayload(data, row) {
    if (row?.source !== "engp") return data;

    const validEventIds = new Set((row.release_events || [])
        .map((event) => String(event.id)));
    const releaseEvents = Object.fromEntries(Object.entries(data.release_events || {})
        .filter(([eventId]) => validEventIds.has(String(eventId))));

    return {
        ...data,
        dates: Object.fromEntries(Object.entries(data.dates || {})
            .filter(([field]) => field === "date_received_penro")),
        release_events: releaseEvents,
        internal_events: {},
    };
}
