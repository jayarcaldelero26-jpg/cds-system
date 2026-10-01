import { Icon } from '@iconify/react';

export default function AppliedFilterChip({ label, value, onRemove }) {
    return <span className="cds-applied-filter-chip inline-flex max-w-full items-center gap-1.5 rounded-full border border-green-200 bg-green-50 py-1 pl-3 pr-1 text-xs text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">
        <span className="font-semibold">{label}:</span><span className="max-w-44 truncate">{value}</span>
        <button type="button" onClick={onRemove} aria-label={`Remove ${label} filter`} className="cds-filter-chip-remove" title={`Remove ${label}`}><Icon icon="lucide:x" width="12" height="12" aria-hidden="true" /></button>
    </span>;
}
