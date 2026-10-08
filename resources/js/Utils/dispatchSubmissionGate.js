const initialPenroDispatchActions = new Set([
    "forward_to_office_penro",
    "dispatch_penro_records_to_tsd",
    "dispatch_penro_records_to_cds_focal",
]);

export function beginInitialPenroDispatch(ref, actionKey) {
    if (!initialPenroDispatchActions.has(actionKey)) return true;
    if (ref.current) return false;

    ref.current = true;
    return true;
}

export function finishInitialPenroDispatch(ref, actionKey) {
    if (initialPenroDispatchActions.has(actionKey)) ref.current = false;
}
