const categoryLabels = {
    CENRO_RECORDS: "CENRO Records Unit",
    CENRO_CDS_CHIEF: "CENRO CDS Chief",
    CENRO_CDS_FOCAL: "CENRO CDS Focal Person",
    PENRO_RECORDS: "PENRO Records Unit",
    OFFICE_OF_THE_PENRO: "Office of the PENRO",
    PENRO_TSD_CHIEF: "PENRO TSD Chief",
    PENRO_CDS_CHIEF: "PENRO CDS Chief",
    PENRO_CDS_FOCAL: "PENRO CDS Focal Person",
    PAMO: "PAMO",
};

const text = (value) => value === null || value === undefined
    ? null : String(value).trim() || null;
const HIDE_FIELD = Symbol("hide-report-context-field");
const sameText = (left, right) => Boolean(text(left) && text(right))
    && text(left).toLowerCase() === text(right).toLowerCase();
const routingFor = (row) => row?.routing || row?.routing_summary || {};

export function submissionKey(row) {
    const source = text(row?.source);
    const id = text(row?.source_id);
    return source && id ? `${source}:${id}` : null;
}

/** Refresh an existing selection from authorized props; never match by ID alone. */
export function refreshSubmissionSelection(current, rows = [], linked = null, fallback = true, preferLinked = false) {
    if (preferLinked && submissionKey(linked)) return rows.find((row) => submissionKey(row) === submissionKey(linked)) || linked;
    const key = submissionKey(current);
    const fresh = key ? rows.find((row) => submissionKey(row) === key)
        || (submissionKey(linked) === key ? linked : null) : null;
    return fresh || (fallback ? linked || rows[0] || null : null);
}

export function custodyContext(row) {
    const routing = routingFor(row);
    const origin = text(row?.target_office);
    const office = text(routing.responsible_office) || origin;
    const category = text(routing.responsible_user_category);
    const location = text(routing.current_location || row?.current_document_location);
    return {
        holderLabel: category ? "Responsible Category" : "Current Location",
        holder: categoryLabels[category] || category
            || (sameText(location, office) ? null : location),
        office,
        officeMatchesOrigin: sameText(office, origin),
    };
}

/** Use the accountable holder in "At ..." statuses, while keeping office context separate. */
export function presentCurrentRoutingStatus(row, status) {
    const value = text(status);
    if (!value || !/^At\s+/i.test(value)) return value;

    const custody = custodyContext(row);
    const holder = sameText(custody.holder, "PAMO") && custody.office
        ? custody.office
        : custody.holder;

    return holder ? `At ${holder}` : value;
}

/** Document type is a stored field; an activity name is never its fallback. */
export function reportContextFields(row, expanded = false) {
    if (!row) return [];
    const module = text(row.module) || text(row.module_name);
    const activity = text(row.activity_name) || text(row.activity);
    const documentType = text(row.document_type) || text(row.report_type);
    const custody = custodyContext(row);
    const routing = routingFor(row);
    const pamoOrigin = routing.route_position?.origin_kind === "pamo_pamb"
        && Boolean(text(routing.originating_office));
    const sourceOffice = text(row.target_office);
    const releaseFallback = row.cenro_release_applicable === false
        ? "Not applicable"
        : row.cenro_release_state === "awaiting_cenro_release"
            ? "Awaiting CENRO release"
            : "Not recorded";
    const fields = [
        ["Tracking Reference", row.tracking_number, false, "Not recorded"],
        ["Workflow / Module", module, false, "Not recorded"],
        ["Activity", sameText(activity, module) || sameText(activity, documentType) ? HIDE_FIELD : activity, false, "Not provided"],
        ["Report / Document Type", documentType, false, "Not provided"],
        ["Protected Area", row.source === "engp" && !text(row.protected_area) ? HIDE_FIELD : row.protected_area, false, "Not recorded"],
        [pamoOrigin ? "Originating Office" : custody.officeMatchesOrigin ? "Originating / Current Office" : "Originating Office", pamoOrigin ? routing.originating_office : sourceOffice, false, "Not recorded"],
        ...(pamoOrigin && text(routing.responsible_office) ? [[
            routing.current_stage === "transit_to_cenro_focal" ? "Receiving Office" : "Current Office",
            routing.responsible_office, false, "Not recorded",
        ]] : []),
        ["Reporting Period", text(row.reporting_period) || text(row.period_label), false, "Not provided"],
    ];
    if (expanded) fields.push(
        ["Date Conducted", row.date_conducted ?? null, true, "Not recorded"],
        ["Date Accomplished", row.date_accomplished ?? null, true, "Not recorded"],
        ["CENRO Release", row.cenro_release_applicable === false ? "Not applicable" : row.date_report_released_cenro ?? null, true, releaseFallback],
        ["PENRO Receipt", row.date_received_penro ?? null, true, "Not recorded"],
        ["Regional Endorsement", row.date_endorsed_regional ?? null, true, "Not recorded"],
    );
    return fields.filter(([, value]) => value !== HIDE_FIELD);
}

/** Show the unmet MOV prerequisite without exposing a blocked custody action. */
export function movPrerequisiteFor(row) {
    const mov = row?.mov_processing;
    if (!row?.canonical_custody_applicable || !mov?.applicable) return null;
    const stage = routingFor(row).current_stage;
    if (stage === "cenro_preparation") {
        if (mov.status_key === "activity_conducted" || mov.status_key === "needs_correction") {
            if (!row.mov_url && !row.current_document) return "Upload MOV / report before review";
            return mov.status_key === "needs_correction" ? "Resubmit for Review" : "Submit for Review";
        }
    }
    if (stage === "cenro_chief" && mov.status_key === "submitted_for_review") return "Review MOV / report";
    return null;
}

export function nextSubmissionAction(row) {
    return movPrerequisiteFor(row) || routingFor(row).next_expected_action || null;
}

/** Reuse per-record server flags, including explicit false, on both detail surfaces. */
export function movActionAvailability(row, context = {}, hideReleaseAction = false) {
    const mov = row?.mov_processing;
    if (!mov?.applicable) return { submit: false, review: false, release: false };
    const flags = row.pamb_action_flags || {};
    return {
        submit: Boolean((flags.can_submit ?? context.can_submit_mov)
            && row.mov_url && ["activity_conducted", "needs_correction"].includes(mov.status_key)),
        review: Boolean((flags.can_review ?? context.can_review_mov) && mov.status_key === "submitted_for_review"),
        release: Boolean(!hideReleaseAction && flags.can_release === true
            && row.cenro_release_applicable !== false && mov.status_key === "ready_for_release"),
    };
}
