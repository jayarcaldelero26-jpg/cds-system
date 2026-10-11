export function historicalRecordIdentity(record) {
    const source = String(record?.source ?? '').trim();
    const id = String(record?.id ?? '').trim();
    return source && id ? `${source}:${id}` : null;
}

const SAFE_TRACE_SOURCE = /^[a-z0-9-]{1,40}$/;

export function createHistoricalDetailTrace({
    source,
    id,
    location = globalThis.location,
    performanceRef = globalThis.performance,
    log = summary => globalThis.console?.info?.('[CDS historical detail trace]', summary),
    activatedAt = null,
} = {}) {
    const safeSource = String(source ?? '');
    const safeId = String(id ?? '');
    if (location?.hostname !== 'cds-system.test'
        || new URLSearchParams(location.search || '').get('__cds_perf') !== '1'
        || !SAFE_TRACE_SOURCE.test(safeSource)
        || !/^\d{1,12}$/.test(safeId)
        || Number(safeId) < 1
        || !Number.isSafeInteger(Number(safeId))
        || typeof performanceRef?.now !== 'function') {
        return null;
    }

    const identity = `${safeSource}:${Number(safeId)}`;
    const route = `/settings/historical-reports/${safeSource}/${Number(safeId)}`;
    const marks = new Map();
    const mark = name => {
        if (!marks.has(name)) marks.set(name, performanceRef.now());
    };
    if (Number.isFinite(activatedAt)) marks.set('activation', activatedAt);
    else mark('activation');

    let traceUrl = null;
    let responseInfo = {};
    let responseIdentity = null;
    let resultOutcome = null;
    let finished = false;
    const duration = (start, end) => {
        if (!marks.has(start) || !marks.has(end)) return null;
        return Math.round(Math.max(0, marks.get(end) - marks.get(start)) * 100) / 100;
    };

    return {
        identity,
        finished: () => finished,
        hasMark: name => marks.has(name),
        mark,
        withDiagnostic(url) {
            try {
                const target = new URL(url, location.origin);
                if (target.origin !== location.origin || target.pathname !== route) return url;
                target.searchParams.set('__cds_perf', '1');
                traceUrl = target.href;
                return traceUrl;
            } catch {
                return url;
            }
        },
        recordResponse(response) {
            responseInfo = {
                status: Number.isInteger(response?.status) ? response.status : null,
                content_type: response?.headers?.get?.('content-type') || null,
                server_timing: response?.headers?.get?.('server-timing') || null,
                server_trace_id: response?.headers?.get?.('x-cds-perf-id') || null,
                server_route: response?.headers?.get?.('x-cds-perf-route') || null,
            };
        },
        recordPayloadIdentity(payloadSource, payloadId) {
            const normalizedSource = String(payloadSource ?? '');
            const normalizedId = Number(payloadId);
            if (SAFE_TRACE_SOURCE.test(normalizedSource) && Number.isSafeInteger(normalizedId) && normalizedId > 0) {
                responseIdentity = `${normalizedSource}:${normalizedId}`;
            }
        },
        setResult(outcome) {
            resultOutcome = outcome;
        },
        result() {
            return resultOutcome;
        },
        finish(outcome) {
            if (finished) return;
            finished = true;
            mark('trace_finished');
            const resource = traceUrl
                ? performanceRef.getEntriesByName?.(traceUrl, 'resource')?.filter(entry => entry.entryType === 'resource').at(-1)
                : null;
            const resourceTiming = resource ? {
                request_to_response_start_ms: Math.round(Math.max(0, resource.responseStart - resource.requestStart) * 100) / 100,
                response_body_ms: Math.round(Math.max(0, resource.responseEnd - resource.responseStart) * 100) / 100,
            } : null;
            log({
                route,
                source: safeSource,
                id: Number(safeId),
                expected_identity: identity,
                response_identity: responseIdentity,
                identity_match: responseIdentity === null ? null : responseIdentity === identity,
                payload_validation: {
                    json_parse: marks.has('json_parse_complete') ? 'valid' : marks.has('json_parse_failed') ? 'invalid' : 'not reached',
                    contract: marks.has('identity_mismatch') ? 'identity mismatch'
                        : marks.has('payload_validated') ? 'valid'
                            : marks.has('contract_check_start') ? 'incomplete' : 'not reached',
                },
                outcome,
                ...responseInfo,
                timings_ms: {
                    activation_to_modal_commit: duration('activation', 'modal_shell_commit'),
                    activation_to_modal_two_frames: duration('activation', 'modal_two_frames'),
                    modal_commit_to_transform_start: duration('modal_shell_commit', 'modal_transition_start'),
                    modal_commit_to_transform_end: duration('modal_shell_commit', 'modal_transition_end'),
                    activation_to_request_dispatch: duration('activation', 'request_dispatch'),
                    dispatch_to_response_headers: duration('request_dispatch', 'response_headers'),
                    headers_to_body_complete: duration('response_headers', 'body_complete'),
                    json_parse: duration('body_complete', 'json_parse_complete'),
                    contract_validation: duration('contract_check_start', 'contract_check_complete'),
                    activation_to_details_commit: duration('activation', 'details_commit'),
                    details_commit_to_two_frames: duration('details_commit', 'details_two_frames'),
                    activation_to_error_commit: duration('activation', 'error_commit'),
                },
                resource_timing: resourceTiming,
            });
        },
    };
}

export function createHistoricalDetailRequestGuard() {
    let current = 0;

    return {
        begin() {
            current += 1;
            return current;
        },
        invalidate() {
            current += 1;
        },
        isCurrent(token) {
            return token === current;
        },
    };
}

export async function fetchHistoricalDetail(url, { fetchImpl = globalThis.fetch, signal, expectedIdentity = null, trace = null } = {}) {
    trace?.mark('request_dispatch');
    const response = await fetchImpl(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
    });
    trace?.recordResponse(response);
    trace?.mark('response_headers');

    if (!response.ok) {
        trace?.mark('http_error');
        const error = new Error(response.status === 403
            ? 'You are not authorized to view this historical record.'
            : response.status === 404
                ? 'This historical record is no longer available.'
                : 'The historical details could not be loaded.');
        error.status = response.status;
        throw error;
    }

    const contentType = response.headers?.get?.('content-type');
    if (!contentType || !/^application\/(?:[a-z0-9.-]+\+)?json(?:\s*;|$)/i.test(contentType.trim())) {
        trace?.mark('invalid_content_type');
        throw new Error('The historical detail response was not JSON.');
    }

    trace?.mark('body_read_start');
    let responseText;
    try {
        responseText = await response.text();
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        trace?.mark('body_read_failed');
        throw new Error('The historical detail response was incomplete.');
    }
    trace?.mark('body_complete');

    let payload;
    try {
        payload = JSON.parse(responseText);
    } catch (error) {
        trace?.mark('json_parse_failed');
        throw new Error('The historical detail response was incomplete.');
    }
    trace?.mark('json_parse_complete');
    const record = payload?.record;
    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    trace?.mark('contract_check_start');
    const validRecord = isObject(record)
        && typeof record.source === 'string' && record.source.trim() !== ''
        && Number.isSafeInteger(record.source_id) && record.source_id > 0
        && typeof record.label === 'string' && record.label.trim() !== ''
        && isObject(record.detail)
        && Array.isArray(record.timeline)
        && Array.isArray(record.activity_timeline)
        && (isObject(record.historical_date_gaps) || Array.isArray(record.historical_date_gaps))
        && isObject(record.timeliness)
        && Array.isArray(record.attachments);

    if (!validRecord) {
        trace?.mark('contract_check_complete');
        throw new Error('The historical detail response was incomplete.');
    }
    trace?.recordPayloadIdentity(record.source, record.source_id);
    if (expectedIdentity && `${record.source}:${record.source_id}` !== expectedIdentity) {
        trace?.mark('contract_check_complete');
        trace?.mark('identity_mismatch');
        throw new Error('The historical detail response did not match the selected record.');
    }
    trace?.mark('contract_check_complete');
    trace?.mark('payload_validated');
    return record;
}
