function Toggle({ id, label, description, checked, disabled, onChange }) {
    return <label htmlFor={id} className="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <span><span className="block text-sm font-bold text-gray-900 dark:text-white">{label}</span><span className="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400">{description}</span></span>
        <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={event => onChange(event.target.checked)} className="mt-1 h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50" />
    </label>;
}

const routeFor = (office, tsd, direct = false) => {
    const penroSegment = ['PENRO Records', ...(office ? ['Office of the PENRO'] : []), ...(tsd ? ['TSD Chief'] : []), 'CDS Focal'].join(' → ');
    const finalSegment = office
        ? 'PENRO CDS Chief → Office of the PENRO → PENRO Records → Regional Office'
        : 'PENRO CDS Chief → PENRO Records → Regional Office';
    const start = direct
        ? `PENRO CDS Focal → ${penroSegment}`
        : `CENRO CDS Focal → CENRO CDS Chief → CENRO Records → ${penroSegment}`;

    return `${start} → ${finalSegment}`;
};

export default function RoutingPositionControlsPanel({ settings, canUpdate, data, errors = {}, processing = false, onToggle, onReason, onSave }) {
    const disabled = !canUpdate || !settings.available || processing;
    return <div className="max-w-3xl">
        <p className="text-xs font-extrabold uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">Report Routing</p>
        <h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Routing Position Controls</h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">Choose which routing positions are included in eligible new routes.</p>

        <div className="mt-5 rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-950 dark:bg-blue-950/30 dark:text-blue-100">
            These settings apply when an eligible report starts routing. Reports with a captured route keep that route. Reports from before routing controls were activated continue to use the all-enabled baseline.
        </div>

        <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {settings.available ? `Settings revision ${settings.version}` : 'Settings revision unavailable'}
            {' · '}{settings.saved_at ? `Last saved ${new Date(settings.saved_at).toLocaleString()}` : 'Initial all-enabled baseline'}
            {' · '}{settings.saved_by || 'System'}{settings.reason ? ` · ${settings.reason}` : ''}
        </p>

        {!settings.available ? <p role="status" className="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Routing settings are unavailable until the approved feature schema is deployed. Routing continues with the existing all-enabled behavior.</p> : <>
            <div className="mt-6 space-y-3">
                <Toggle id="office-penro-enabled" label="Include Office of the PENRO in routing" description="When unchecked, eligible new routes skip the initial Office handoff and final Office review." checked={data.office_penro_enabled} disabled={disabled} onChange={value => onToggle('office_penro_enabled', value)} />
                <Toggle id="penro-tsd-enabled" label="Include PENRO TSD Chief in routing" description="When unchecked, eligible new routes skip the TSD receipt and forwarding steps." checked={data.penro_tsd_chief_enabled} disabled={disabled} onChange={value => onToggle('penro_tsd_chief_enabled', value)} />
            </div>
            <div className="mt-5 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 className="text-sm font-bold text-gray-900 dark:text-white">Route for new reports</h3>
                <p className="mt-3 text-xs font-semibold text-gray-500 dark:text-gray-400">CENRO-origin profile</p>
                <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-300">{routeFor(data.office_penro_enabled, data.penro_tsd_chief_enabled)}</p>
                <p className="mt-3 text-xs font-semibold text-gray-500 dark:text-gray-400">Direct-to-PENRO profile</p>
                <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-300">{routeFor(data.office_penro_enabled, data.penro_tsd_chief_enabled, true)}</p>
                <p className="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">When Office is not included, the CDS Chief recommends directly to PENRO Records for receipt and regional release. This recommendation is not an Office approval.</p>
            </div>
            {canUpdate ? <>
                <label htmlFor="routing-change-reason" className="mt-5 block text-xs font-bold text-gray-700 dark:text-gray-300">Change note (optional)</label>
                <input id="routing-change-reason" maxLength={500} value={data.reason} disabled={disabled} onChange={event => onReason(event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-green-600 focus:outline-none focus:ring-2 focus:ring-green-600/20 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                {errors.expected_version && <p role="alert" className="mt-2 text-sm text-red-700 dark:text-red-300">{errors.expected_version}</p>}
                {errors.office_penro_enabled && <p role="alert" className="mt-2 text-sm text-red-700 dark:text-red-300">{errors.office_penro_enabled}</p>}
                {errors.penro_tsd_chief_enabled && <p role="alert" className="mt-2 text-sm text-red-700 dark:text-red-300">{errors.penro_tsd_chief_enabled}</p>}
                <button type="button" disabled={disabled} onClick={onSave} className="mt-5 inline-flex min-h-10 items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-900">{processing ? 'Saving…' : 'Save routing settings'}</button>
            </> : <p className="mt-5 text-sm text-gray-500 dark:text-gray-400">You have view access. An administrator with routing settings update access can change these values.</p>}
        </>}
    </div>;
}
