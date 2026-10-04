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

/** Document type is a stored field; an activity name is never its fallback. */
export function reportContextFields(row, expanded = false) {
    if (!row) return [];
    const module = text(row.module) || text(row.module_name);
    const activity = text(row.activity_name) || text(row.activity);
    const documentType = text(row.document_type) || text(row.report_type);
    const custody = custodyContext(row);
    const fields = [
        ["Tracking Reference", row.tracking_number],
        ["Workflow / Module", module],
        ["Activity", sameText(activity, module) || sameText(activity, documentType) ? null : activity],
        ["Report / Document Type", documentType],
        ["Protected Area", row.protected_area],
        [custody.officeMatchesOrigin ? "Originating / Current Office" : "Originating Office", row.target_office],
        ["Reporting Period", text(row.reporting_period) || text(row.period_label)],
    ];
    if (expanded) fields.push(
        ["Date Conducted", row.date_conducted, true],
        ["Date Accomplished", row.date_accomplished, true],
        ["CENRO Release", row.date_report_released_cenro, true],
        ["PENRO Receipt", row.date_received_penro, true],
        ["Regional Endorsement", row.date_endorsed_regional, true],
    );
    return fields.filter(([, value]) => text(value) !== null);
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
